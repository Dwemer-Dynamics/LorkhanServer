<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use LorkhanServer\Application\ProviderFactory;
use PDO;
use Throwable;

/**
 * CHIM-style Player2 usage heartbeat. Player2 attributes play to a game from GET /v1/health with its game key once a minute.
 * Only an installation whose active session/generation had game traffic within 180 s and a succeeded Player2 dialogue call
 * within 300 s is pinged: one attempt per minute, no retries, outside transactions, and never recorded as a provider attempt.
 */
final class Player2HealthHeartbeat
{
    public const ACTIVITY_TTL_SECONDS = 180;
    public const USE_TTL_SECONDS = 300;
    public const INTERVAL_SECONDS = 60;
    public const CONNECT_TIMEOUT_MS = 2000;
    public const TOTAL_TIMEOUT_MS = 5000;
    private const MAX_INSTALLATIONS = 4;
    private const MAX_RESPONSE_BYTES = 65_536;

    /**
     * @param (callable(string,array<int,mixed>):int)|null $transport returns the HTTP status, or 0 for a transport failure
     * @param (callable():\DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config,
        private readonly mixed $transport = null,
        private readonly mixed $clock = null,
    ) {}

    /** Send due heartbeats; failures are logged by status only and never escape into the worker loop. */
    public function tick(): int
    {
        if (($this->config['player2_health_heartbeat'] ?? true) === false || $this->db->inTransaction()) return 0;
        $now = ($this->clock ?? static fn(): \DateTimeImmutable => new \DateTimeImmutable('now', new \DateTimeZone('UTC')))()
            ->format('Y-m-d\TH:i:s.uP');
        try {
            $candidates = $this->candidates($now);
        } catch (Throwable $error) {
            Logger::warn('Player2 health heartbeat unavailable: error=' . $error::class);
            return 0;
        }
        $sent = 0;
        foreach ($candidates as $candidate) {
            try {
                if ($this->beat($candidate, $now)) ++$sent;
            } catch (Throwable $error) {
                Logger::warn('Player2 health heartbeat skipped: installation_id=' . $candidate['installation_id'] . ' error=' . $error::class);
            }
        }
        return $sent;
    }

    /** Latest succeeded Player2 dialogue call of each active, recently played session, on its still-current connector revision. */
    private function candidates(string $now): array
    {
        $query = $this->db->prepare("SELECT s.installation_id,s.session_id,s.generation,p2.configuration_id,p2.configuration_revision,p2.content
            FROM sessions s JOIN installations i ON i.installation_id=s.installation_id AND i.revoked_at IS NULL
            CROSS JOIN LATERAL (SELECT c.configuration_id,c.current_revision AS configuration_revision,r.content
                FROM turns t JOIN provider_attempts a ON a.request_id=t.request_id AND a.turn_id=t.turn_id
                JOIN configuration_sets c ON c.configuration_id::text=a.metadata->>'configuration_id' AND c.installation_id=s.installation_id
                    AND c.kind='provider' AND c.deleted_at IS NULL AND c.current_revision::text=a.metadata->>'configuration_revision'
                JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision
                    AND r.content->>'driver'='openai-compatible' AND r.content->>'service'='player2'
                WHERE t.session_id=s.session_id AND t.generation=s.generation
                    AND t.accepted_at>CAST(:turn_now AS timestamptz)-interval '15 minutes'
                    AND a.provider_kind='llm' AND a.operation='complete_turn' AND a.provider_name='openai-compatible' AND a.state='succeeded'
                    AND a.finished_at>CAST(:use_now AS timestamptz)-interval '" . self::USE_TTL_SECONDS . " seconds'
                ORDER BY a.finished_at DESC,a.provider_attempt_id DESC LIMIT 1) p2
            WHERE s.state='active' AND NOT s.archived
                AND EXISTS(SELECT 1 FROM source_events e WHERE e.session_id=s.session_id AND e.generation=s.generation
                    AND e.received_at>CAST(:activity_now AS timestamptz)-interval '" . self::ACTIVITY_TTL_SECONDS . " seconds')
            ORDER BY s.installation_id LIMIT " . self::MAX_INSTALLATIONS);
        $query->execute(['turn_now' => $now, 'use_now' => $now, 'activity_now' => $now]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private function beat(array $candidate, string $now): bool
    {
        $request = ProviderFactory::player2HealthRequest($this->config, ['configuration_id' => (string) $candidate['configuration_id'],
            'revision' => (int) $candidate['configuration_revision'],
            'content' => json_decode((string) $candidate['content'], true, 64, JSON_THROW_ON_ERROR)]);
        // One autocommitted claim per installation-minute, so restarted or concurrent workers cannot double-send.
        $claim = $this->db->prepare("INSERT INTO player2_health_heartbeats AS h
                (installation_id,session_id,generation,configuration_id,configuration_revision,attempted_at)
            VALUES(:installation,:session,:generation,:configuration,:revision,CAST(:now AS timestamptz))
            ON CONFLICT(installation_id) DO UPDATE SET session_id=EXCLUDED.session_id,generation=EXCLUDED.generation,
                configuration_id=EXCLUDED.configuration_id,configuration_revision=EXCLUDED.configuration_revision,
                attempted_at=EXCLUDED.attempted_at,http_status=NULL
            WHERE h.attempted_at<=EXCLUDED.attempted_at-interval '" . self::INTERVAL_SECONDS . " seconds'
                OR h.attempted_at>EXCLUDED.attempted_at+interval '1 hour'
            RETURNING installation_id");
        $claim->execute(['installation' => $candidate['installation_id'], 'session' => $candidate['session_id'],
            'generation' => (int) $candidate['generation'], 'configuration' => $candidate['configuration_id'],
            'revision' => (int) $candidate['configuration_revision'], 'now' => $now]);
        if ($claim->fetchColumn() === false) return false;
        $options = $request['curl'] + [CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => self::CONNECT_TIMEOUT_MS, CURLOPT_TIMEOUT_MS => self::TOTAL_TIMEOUT_MS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_HTTPHEADER => $request['headers']];
        try {
            $status = ($this->transport ?? self::request(...))($request['url'], $options);
        } catch (Throwable) {
            $status = 0;
        }
        $status = $status >= 100 && $status <= 599 ? $status : 0;
        $ok = $status >= 200 && $status < 300;
        $this->db->prepare('UPDATE player2_health_heartbeats SET http_status=:status,'
            . 'succeeded_at=CASE WHEN CAST(:ok AS boolean) THEN CAST(:succeeded AS timestamptz) ELSE succeeded_at END '
            . 'WHERE installation_id=:installation AND attempted_at=CAST(:now AS timestamptz)')
            ->execute(['status' => $status, 'ok' => $ok ? 'true' : 'false', 'succeeded' => $now,
                'installation' => $candidate['installation_id'], 'now' => $now]);
        if (!$ok) Logger::warn('Player2 health heartbeat failed: installation_id=' . $candidate['installation_id'] . ' status=' . $status);
        return $ok;
    }

    /** @param array<int,mixed> $options */
    private static function request(string $url, array $options): int
    {
        $handle = curl_init($url);
        if ($handle === false) return 0;
        $received = 0;
        curl_setopt_array($handle, $options + [CURLOPT_WRITEFUNCTION => static function ($handle, string $bytes) use (&$received): int {
            unset($handle);
            $received += strlen($bytes);
            return $received > self::MAX_RESPONSE_BYTES ? 0 : strlen($bytes);
        }]);
        $completed = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        return $completed === false ? 0 : $status;
    }
}
