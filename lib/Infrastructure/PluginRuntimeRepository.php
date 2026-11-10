<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use DomainException;
use LorkhanServer\Application\PluginRegistry;
use LorkhanServer\Protocol\PluginContract;
use OutOfBoundsException;
use PDO;
use Throwable;
use UnexpectedValueException;

/**
 * Session-generation addon registrations and immutable plugin events. Nothing stored here grants access by itself:
 * every read restores PluginRegistry against the live session, installed package rows and enabled policy.
 */
final class PluginRuntimeRepository
{
    public const EVENT_JOB = 'plugin.event.dispatch';
    private static ?string $serverVersion = null;

    public function __construct(private readonly PDO $db, private readonly array $config = [])
    {
    }

    public static function serverVersion(): string
    {
        return self::$serverVersion ??= trim((string) @file_get_contents(dirname(__DIR__, 2) . '/version.txt'));
    }

    /** Content-addressed immutable package trees written by PluginPackageRepository. */
    public function storeRoot(): string
    {
        return rtrim((string) ($this->config['plugin_package_storage_path'] ?? '/var/lib/lorkhanserver/plugin-packages'), '/') . '/store';
    }

    public function npcData(string $installation, string $pluginId, bool $writable): PluginNpcData
    {
        return new PluginNpcData($this->db, $installation, $pluginId, $writable);
    }

    /**
     * Apply one registration message and persist the resulting active set for this exact session generation.
     * $receipt runs inside the same transaction (same PDO), so the stored HTTP receipt and the state change commit together.
     *
     * @param callable(array<string,mixed>):void|null $receipt
     */
    public function register(string $installation, array $message, ?callable $receipt = null): array
    {
        $this->db->beginTransaction();
        try {
            [$registry, $packages] = $this->state($installation, $message['session_id'], $message['generation'], true);
            [$registry, $results] = $registry->apply($message, ...$this->inputs($packages));
            $this->db->prepare("DELETE FROM plugin_registrations registration USING sessions session
                WHERE registration.session_id = session.session_id AND session.installation_id = :installation
                  AND (session.state <> 'active' OR registration.session_id = :session)")
                ->execute(['installation' => $installation, 'session' => $message['session_id']]);
            $insert = $this->db->prepare('INSERT INTO plugin_registrations (session_id,generation,plugin_id,installation_id,version,manifest_sha256,entry)
                VALUES (:session,:generation,:plugin,:installation,:version,:sha,CAST(:entry AS jsonb))');
            foreach ($registry->entries() as $id => $entry) {
                $insert->execute(['session' => $message['session_id'], 'generation' => $message['generation'], 'plugin' => $id,
                    'installation' => $installation, 'version' => $entry['version'], 'sha' => $entry['manifest_sha256'],
                    'entry' => json_encode($entry, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
            }
            $this->withdraw($message['session_id'], $message['generation'], $registry);
            $body = ['schema' => PluginContract::REGISTRATION_ACCEPTED, 'message_id' => $message['message_id'],
                'request_id' => $message['request_id'], 'session_id' => $message['session_id'], 'generation' => $message['generation'],
                'plugins' => $results];
            if ($receipt !== null) $receipt($body);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
        return $body;
    }

    /** Validate one event against the live registry; returns its declared rate cap. */
    public function validateEvent(string $installation, array $message): array
    {
        [$registry] = $this->state($installation, $message['session_id'], $message['generation'], false);
        return $registry->event($message);
    }

    /**
     * Persist the immutable source event, then its derived hook job and the HTTP receipt, atomically.
     *
     * @param callable():void|null $receipt Runs inside the same transaction (same PDO).
     */
    public function persistEvent(string $installation, array $message, ?callable $receipt = null): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('INSERT INTO plugin_events (installation_id,message_id,session_id,generation,plugin_id,plugin_version,event,message,observed_at)
                VALUES (:installation,:message,:session,:generation,:plugin,:version,:event,CAST(:body AS jsonb),:observed)')
                ->execute(['installation' => $installation, 'message' => $message['message_id'], 'session' => $message['session_id'],
                    'generation' => $message['generation'], 'plugin' => $message['plugin_id'], 'version' => $message['plugin_version'],
                    'event' => $message['event'], 'body' => json_encode($message, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'observed' => $message['observed_at']]);
            (new JobRepository($this->db))->enqueue(Uuid::v4(), self::EVENT_JOB, 1, $installation . '|' . $message['message_id'],
                ['installation_id' => $installation, 'message_id' => $message['message_id']], 2);
            if ($receipt !== null) $receipt();
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    /** @return array<string,mixed>|null The stored client event message. */
    public function storedEvent(string $installation, string $messageId): ?array
    {
        $query = $this->db->prepare('SELECT message FROM plugin_events WHERE installation_id = :installation AND message_id = :message');
        $query->execute(['installation' => $installation, 'message' => $messageId]);
        $json = $query->fetchColumn();
        return is_string($json) ? json_decode($json, true, 64, JSON_THROW_ON_ERROR) : null;
    }

    /**
     * Active addons with their verified package tree for hook loading, in plugin ID order. A stale, ended or
     * unnegotiated session has none; disable, removal or update takes effect on the next call.
     *
     * @return list<array{plugin_id:string,version:string,archive_sha256:string,manifest_sha256:string,revision:int,slots:array<string,int>,events:list<string>}>
     */
    public function activePlugins(string $installation, string $sessionId, int $generation): array
    {
        try {
            [$registry, $packages] = $this->state($installation, $sessionId, $generation, false);
        } catch (OutOfBoundsException | UnexpectedValueException | DomainException) {
            return [];
        }
        $slots = [];
        foreach ($registry->promptSlots() as $slot) $slots[$slot['plugin_id']][$slot['slot']] = $slot['max_chars'];
        $active = [];
        foreach ($registry->entries() as $id => $entry) {
            $row = $packages[$id];
            $active[] = ['plugin_id' => $id, 'version' => $row['version'], 'archive_sha256' => $row['archive_sha256'],
                'manifest_sha256' => $row['manifest_sha256'], 'revision' => (int) $row['revision'], 'slots' => $slots[$id] ?? [],
                'events' => $entry['events']];
        }
        usort($active, static fn(array $a, array $b): int => strcmp($a['plugin_id'], $b['plugin_id']));
        return $active;
    }

    /**
     * Live registry for one session generation, or null when stale, ended or unnegotiated. Pending addon intents of plugins
     * no longer active in it are withdrawn on this access.
     */
    public function registry(string $installation, string $sessionId, int $generation): ?PluginRegistry
    {
        try {
            [$registry] = $this->state($installation, $sessionId, $generation, false);
        } catch (OutOfBoundsException | UnexpectedValueException | DomainException) {
            return null;
        }
        $this->withdraw($sessionId, $generation, $registry);
        return $registry;
    }

    /**
     * Retire pending intents of disabled, removed, updated, unregistered or older-generation addons like an interrupt does:
     * no outcome is invented, so the client's actual terminal result (even a committed non-cancellable effect, which cannot
     * be undone) is still stored if it arrives within the result window.
     */
    private function withdraw(string $sessionId, int $generation, PluginRegistry $registry): void
    {
        $active = [];
        foreach ($registry->entries() as $id => $entry) $active[] = $id . '@' . $entry['version'];
        $this->db->prepare("WITH withdrawn AS (UPDATE action_intents SET state = 'terminal' WHERE session_id = :session
                AND plugin_id IS NOT NULL AND state <> 'terminal'
                AND (generation <> :generation OR NOT (plugin_id || '@' || plugin_version = ANY (CAST(:active AS text[]))))
                RETURNING action_id)
            UPDATE action_delivery delivery SET terminal_at = COALESCE(delivery.terminal_at, clock_timestamp()), continuation_state = 'none',
                updated_at = clock_timestamp() FROM withdrawn WHERE delivery.action_id = withdrawn.action_id")
            ->execute(['session' => $sessionId, 'generation' => $generation, 'active' => '{' . implode(',', $active) . '}']);
    }

    /** @return array{0:PluginRegistry,1:array<string,array<string,mixed>>} */
    private function state(string $installation, string $sessionId, int $generation, bool $lock): array
    {
        $query = $this->db->prepare('SELECT installation_id, generation, state, capabilities, client_version FROM sessions WHERE session_id = :id'
            . ($lock ? ' FOR UPDATE' : ''));
        $query->execute(['id' => $sessionId]);
        $session = $query->fetch(PDO::FETCH_ASSOC);
        if (!$session || $session['state'] !== 'active' || !hash_equals((string) $session['installation_id'], $installation)) {
            throw new OutOfBoundsException('unknown_session');
        }
        if ((int) $session['generation'] !== $generation) throw new UnexpectedValueException('stale_generation');
        $capabilities = array_values(array_filter(explode(',', trim((string) $session['capabilities'], '{}')), 'strlen'));
        $entries = $this->db->prepare('SELECT entry FROM plugin_registrations WHERE session_id = :session AND generation = :generation ORDER BY plugin_id');
        $entries->execute(['session' => $sessionId, 'generation' => $generation]);
        $entries = array_map(static fn(string $json): array => json_decode($json, true, 64, JSON_THROW_ON_ERROR), $entries->fetchAll(PDO::FETCH_COLUMN));
        $session = ['session_id' => $sessionId, 'generation' => $generation, 'capabilities' => $capabilities,
            'client_version' => (string) $session['client_version']];
        if ($entries === [] && !$lock) return [PluginRegistry::forSession($session, self::serverVersion()), []];
        $packages = [];
        $rows = $this->db->prepare("SELECT plugin_id, version, manifest, manifest_sha256, enabled, archive_sha256, revision
            FROM plugin_packages WHERE installation_id = :installation AND state = 'installed'");
        $rows->execute(['installation' => $installation]);
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['manifest'] = json_decode((string) $row['manifest'], true, 64, JSON_THROW_ON_ERROR);
            $packages[$row['plugin_id']] = $row;
        }
        return [PluginRegistry::restore($session, self::serverVersion(), $entries, ...$this->inputs($packages)), $packages];
    }

    /** @return array{0:array<string,array{manifest:array<string,mixed>,sha256:string}>,1:array<string,bool>} */
    private function inputs(array $packages): array
    {
        $installed = []; $policy = [];
        foreach ($packages as $id => $row) {
            $installed[$id] = ['manifest' => $row['manifest'], 'sha256' => (string) $row['manifest_sha256']];
            $policy[$id] = filter_var($row['enabled'], FILTER_VALIDATE_BOOL);
        }
        return [$installed, $policy];
    }
}
