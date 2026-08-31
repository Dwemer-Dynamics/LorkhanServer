<?php

declare(strict_types=1);

namespace LORKHANserver\Infrastructure;

use PDO;
use RuntimeException;

/**
 * Read-only access to the server-owned action catalog and current action policy.
 */
final class ActionCatalogRepository
{
    public function __construct(private readonly PDO $db) {}

    /** @return list<array<string,mixed>> */
    public function enabledDefinitions(): array
    {
        $rows = $this->db->query(
            'SELECT action_name, tier, description, parameter_schema, result_schema, client_capability, server_owned, '
            . 'terminal_result_required, continuation_capable '
            . 'FROM action_catalog WHERE enabled = true ORDER BY action_name'
        )->fetchAll();

        return array_map(fn(array $row): array => $this->definition($row), $rows);
    }

    /**
     * Loads the active session boundary, enabled catalog rows, and the most-specific current policy.
     * The returned definitions are not yet policy-filtered; ActionPolicyValidator owns that decision.
     *
     * @return array{session:array<string,mixed>,policy:?array<string,mixed>,definitions:list<array<string,mixed>>}
     */
    public function loadForSession(string $sessionId, int $generation): array
    {
        $stmt = $this->db->prepare(
            "SELECT session_id, installation_id, profile_id, playthrough_id, generation, capabilities, enabled_actions "
            . "FROM sessions WHERE session_id = :session AND generation = :generation AND state = 'active'"
        );
        $stmt->execute(['session' => $sessionId, 'generation' => $generation]);
        $session = $stmt->fetch();
        if (!$session) {
            throw new RuntimeException('unknown_session');
        }

        $session['generation'] = (int) $session['generation'];
        $session['capabilities'] = $this->parsePgArray((string) $session['capabilities']);
        $session['enabled_actions'] = $this->parsePgArray((string) $session['enabled_actions']);

        return [
            'session' => $session,
            'policy' => $this->currentPolicy((string) $session['installation_id'], (string) $session['profile_id']),
            'definitions' => $this->enabledDefinitions(),
        ];
    }

    /**
     * A profile policy overrides an installation-wide policy. Ordering is deterministic when legacy
     * data contains more than one current policy at the same specificity.
     *
     * @return array<string,mixed>|null
     */
    public function currentPolicy(string $installationId, ?string $profileId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT c.configuration_id, c.profile_id, c.name, c.current_revision AS revision, r.content "
            . "FROM configuration_sets c "
            . "JOIN configuration_revisions r ON r.configuration_id = c.configuration_id "
            . "AND r.revision = c.current_revision "
            . "WHERE c.installation_id = :installation AND c.kind = 'action_policy' "
            . "AND c.deleted_at IS NULL AND (c.profile_id IS NULL OR c.profile_id = :profile) "
            . "ORDER BY (c.profile_id IS NOT NULL) DESC, c.name, c.configuration_id LIMIT 1"
        );
        $stmt->execute(['installation' => $installationId, 'profile' => $profileId]);
        $row = $stmt->fetch();
        if (!$row) {
            return null;
        }

        $row['revision'] = (int) $row['revision'];
        $row['content'] = $this->json($row['content']);
        return $row;
    }

    public function claimContinuation(string $actionId,string $continuationTurnId,string $sessionId,int $generation):bool
    {
        $this->db->beginTransaction();
        try {
            $statement=$this->db->prepare("UPDATE action_delivery d SET continuation_state='consumed',continuation_turn_id=:turn,updated_at=clock_timestamp() "
                ."FROM action_results r,action_intents a WHERE d.action_id=:action AND r.action_id=d.action_id AND a.action_id=d.action_id "
                ."AND a.session_id=:session AND a.generation=:generation AND a.followup_enabled AND d.continuation_state='eligible' "
                ."AND d.terminal_at IS NOT NULL AND NOT EXISTS(SELECT 1 FROM action_delivery x WHERE x.continuation_turn_id=:turn)");
            $statement->execute(['action'=>$actionId,'turn'=>$continuationTurnId,'session'=>$sessionId,'generation'=>$generation]);
            $claimed = $statement->rowCount() === 1;
            $this->db->commit();
            return $claimed;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function definition(array $row): array
    {
        return [
            'name' => (string) $row['action_name'],
            'tier' => (int) $row['tier'],
            'enabled'=>true,
            'description' => (string) $row['description'],
            'parameter_schema' => $this->json($row['parameter_schema']),
            'result_schema' => $this->json($row['result_schema']),
            'client_capability' => (string) $row['client_capability'],
            'server_owned' => $this->boolean($row['server_owned']),
            'terminal_result_required'=>$this->boolean($row['terminal_result_required']),
            'continuation_capable'=>$this->boolean($row['continuation_capable']),
        ];
    }

    /** @return array<string,mixed> */
    private function json(mixed $value): array
    {
        $decoded = is_array($value) ? $value : json_decode((string) $value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new RuntimeException('invalid_action_configuration');
        }
        return $decoded;
    }

    /** @return list<string> */
    private function parsePgArray(string $value): array
    {
        if ($value === '{}') {
            return [];
        }
        return array_values(str_getcsv(trim($value, '{}'), ',', '"', '\\'));
    }

    private function boolean(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't' || $value === 'true';
    }
}
