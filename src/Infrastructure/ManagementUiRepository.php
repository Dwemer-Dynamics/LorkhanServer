<?php

declare(strict_types=1);

namespace ALMSIVIserver\Infrastructure;

use PDO;

final class ManagementUiRepository
{
    public function __construct(private readonly PDO $db) {}

    /** Load the bounded datasets used by the server-style home dashboard. */
    public function dashboard(): array
    {
        $current = $this->one(
            "SELECT s.state,s.created_at,s.openmw_version,s.lua_api_revision,s.client_version,s.platform,"
            . "p.name AS profile_name,pt.name AS playthrough_name "
            . "FROM sessions s LEFT JOIN profiles p ON p.profile_id=s.profile_id "
            . "LEFT JOIN playthroughs pt ON pt.playthrough_id=s.playthrough_id "
            . "ORDER BY (s.state='active') DESC,s.created_at DESC LIMIT 1"
        );

        return [
            'database_version' => (string) $this->db->query('SHOW server_version')->fetchColumn(),
            'current' => $current,
            'dialogue' => $this->all(
                "SELECT COALESCE(speaker->>'display_name',speaker->>'name',speaker->>'record_id','Unknown') AS speaker,"
                . "text,delivery_state,emitted_at FROM dialogue_utterances ORDER BY emitted_at DESC LIMIT 12"
            ),
            'stats' => [
                'Installations' => $this->count('installations', 'revoked_at IS NULL'),
                'Sessions' => $this->count('sessions'),
                'Turns' => $this->count('turns'),
                'Memories' => $this->count('memory_records', 'deleted_at IS NULL'),
                'Relationships' => $this->count('relationship_records', 'deleted_at IS NULL'),
                'Queued Jobs' => $this->count('durable_jobs', "state='queued'"),
            ],
            'latest_narrative' => $this->one(
                "SELECT kind,title,content,created_at FROM narrative_records WHERE deleted_at IS NULL "
                . "ORDER BY created_at DESC LIMIT 1"
            ),
            'words' => $this->all(
                "SELECT word,count(*)::int AS uses FROM "
                . "(SELECT text FROM dialogue_utterances ORDER BY emitted_at DESC LIMIT 100) d "
                . "CROSS JOIN LATERAL regexp_split_to_table(lower(d.text),'[^[:alnum:]_]+') AS word "
                . "WHERE length(word)>3 GROUP BY word ORDER BY uses DESC,word LIMIT 12"
            ),
            'runtime' => [
                'Active Sessions' => $this->count('sessions', "state='active'"),
                'Pending Dialogue' => $this->count('dialogue_utterances', "delivery_state='pending'"),
                'Terminal Actions' => $this->count('action_results'),
                'Dead Jobs' => $this->count('durable_jobs', "state='dead'"),
                'Knowledge Records' => $this->count('knowledge_documents', 'deleted_at IS NULL'),
                'Provider Attempts' => $this->count('provider_attempts'),
            ],
        ];
    }

    /** Load the inspection tabs rendered directly inside the Roleplay PHP page. */
    public function roleplay(): array
    {
        return [
            'events' => $this->rows('events'),
            'responses' => $this->rows('responses'),
            'memories' => $this->rows('memories'),
            'relationships' => $this->rows('relationships'),
            'narratives' => $this->rows('narratives'),
            'knowledge' => $this->rows('knowledge'),
        ];
    }

    /** Return one of the allowlisted bounded datasets used by embedded management pages. */
    public function rows(string $view): array
    {
        $sql = match ($view) {
            'events', 'request_logs' => "SELECT event_kind AS type,schema_name AS schema,request_id,turn_id,occurred_at FROM source_events ORDER BY received_at DESC LIMIT 100",
            'responses' => "SELECT COALESCE(speaker->>'display_name',speaker->>'name',speaker->>'record_id','Unknown') AS speaker,text,delivery_state,emitted_at FROM dialogue_utterances ORDER BY emitted_at DESC LIMIT 100",
            'memories' => "SELECT tier,content,occurred_at,updated_at FROM memory_records WHERE deleted_at IS NULL ORDER BY occurred_at DESC LIMIT 100",
            'relationships', 'relationship_logs' => "SELECT COALESCE(actor_identity->>'display_name',actor_identity->>'name',actor_identity->>'record_id',actor_identity::text) AS actor,disposition,affinity,source_mode,updated_at FROM relationship_records WHERE deleted_at IS NULL ORDER BY updated_at DESC LIMIT 100",
            'narratives' => "SELECT kind,title,content,created_at FROM narrative_records WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 100",
            'knowledge', 'worldknowledge' => "SELECT title,left(content,500) AS content,created_at FROM knowledge_documents WHERE deleted_at IS NULL ORDER BY created_at DESC LIMIT 100",
            'characters', 'profiles' => "SELECT name,current_revision,actor_identity,created_at FROM profiles WHERE deleted_at IS NULL ORDER BY name LIMIT 100",
            'llm', 'tts', 'stt' => "SELECT c.name,c.current_revision,r.content,r.created_at FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.kind='provider' AND c.deleted_at IS NULL ORDER BY c.name LIMIT 100",
            'prompts' => "SELECT c.name,c.current_revision,r.content,r.created_at FROM configuration_sets c JOIN configuration_revisions r ON r.configuration_id=c.configuration_id AND r.revision=c.current_revision WHERE c.kind='prompt' AND c.deleted_at IS NULL ORDER BY c.name LIMIT 100",
            'actions' => "SELECT action_name,tier,client_capability,enabled,description FROM action_catalog ORDER BY tier,action_name LIMIT 100",
            'global_settings' => "SELECT display_name,last_seen_at,created_at FROM installations WHERE revoked_at IS NULL ORDER BY last_seen_at DESC LIMIT 100",
            'autonomy' => "SELECT kind,enabled,interval_seconds,cooldown_seconds,last_triggered_at,updated_at FROM autonomy_schedules ORDER BY updated_at DESC LIMIT 100",
            'playthroughs' => "SELECT p.name AS playthrough,pr.name AS profile,p.current_revision,p.content_fingerprint,p.created_at FROM playthroughs p JOIN profiles pr ON pr.profile_id=p.profile_id WHERE p.deleted_at IS NULL ORDER BY p.name LIMIT 100",
            'jobs' => "SELECT job_type,state,attempt_count,max_attempts,next_run_at,last_error_code,updated_at FROM durable_jobs ORDER BY updated_at DESC LIMIT 100",
            'provider_attempts' => "SELECT provider_kind,provider_name,operation,state,duration_ms,error_code,started_at FROM provider_attempts ORDER BY started_at DESC LIMIT 100",
            'backup_health' => "SELECT state,format_version,byte_count,created_at,restored_at FROM backup_records ORDER BY created_at DESC LIMIT 100",
            'diagnostics' => "SELECT category,action,scope,created_at FROM operational_audit ORDER BY created_at DESC LIMIT 100",
            default => [],
        };

        if ($sql === []) return [];
        return array_map(fn(array $row): array => $this->redactRow($row), $this->all($sql));
    }

    private function count(string $table, ?string $where = null): int
    {
        $allowlisted = [
            'installations', 'sessions', 'turns', 'memory_records', 'relationship_records',
            'durable_jobs', 'dialogue_utterances', 'action_results', 'knowledge_documents',
            'provider_attempts',
        ];
        if (!in_array($table, $allowlisted, true)) return 0;
        $sql = 'SELECT count(*) FROM ' . $table . ($where === null ? '' : ' WHERE ' . $where);
        return (int) $this->db->query($sql)->fetchColumn();
    }

    private function one(string $sql): ?array
    {
        $row = $this->db->query($sql)->fetch();
        return $row === false ? null : $this->redactRow($row);
    }

    private function all(string $sql): array
    {
        return $this->db->query($sql)->fetchAll();
    }

    private function redactRow(array $row): array
    {
        foreach ($row as $key => $value) {
            if (!is_string($value) || ($value === '' || ($value[0] !== '{' && $value[0] !== '['))) continue;
            try {
                $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
                $row[$key] = $this->redactValue($decoded);
            } catch (\JsonException) {
                // Non-JSON text is displayed as text and escaped by the template.
            }
        }
        return $row;
    }

    private function redactValue(mixed $value, string $key = ''): mixed
    {
        if (preg_match('/(?:api[_-]?key|secret|password|authorization|token)/i', $key) === 1) return '[redacted]';
        if (!is_array($value)) return $value;
        foreach ($value as $childKey => &$childValue) {
            $childValue = $this->redactValue($childValue, (string) $childKey);
        }
        return $value;
    }
}
