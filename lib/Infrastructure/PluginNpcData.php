<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use InvalidArgumentException;
use LogicException;
use PDO;

/**
 * Hook-facing NPC data for one installation and one addon namespace. Actors are exact TES3 identities
 * (record + content file + RefNum index) in the current playthrough; names never resolve an NPC.
 */
final class PluginNpcData
{
    public const MAX_BYTES = 16_384;
    /** @var array<string,?int> */
    private array $resolved = [];

    public function __construct(private readonly PDO $db, private readonly string $installation,
        private readonly string $pluginId, private readonly bool $writable)
    {
    }

    /** @param array<string,mixed> $actor @return array<string,mixed>|null */
    public function get(array $actor): ?array
    {
        $npc = $this->npcId($actor);
        return $npc === null ? null : (new NpcPluginDataRepository($this->db, $this->installation))->getPluginData($npc, $this->pluginId);
    }

    /** @param array<string,mixed> $actor @param array<string,mixed> $data */
    public function set(array $actor, array $data): bool
    {
        if (!$this->writable) throw new LogicException('plugin_data_read_only');
        $encoded = json_encode((object) $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 16);
        if (strlen($encoded) > self::MAX_BYTES) throw new InvalidArgumentException('plugin_data_too_large');
        $npc = $this->npcId($actor);
        return $npc !== null && (new NpcPluginDataRepository($this->db, $this->installation))->setPluginData($npc, $this->pluginId, $data);
    }

    /** @param array<string,mixed> $actor */
    public function delete(array $actor): bool
    {
        if (!$this->writable) throw new LogicException('plugin_data_read_only');
        $npc = $this->npcId($actor);
        return $npc !== null && (new NpcPluginDataRepository($this->db, $this->installation))->deletePluginData($npc, $this->pluginId);
    }

    /** @param array<string,mixed> $actor */
    private function npcId(array $actor): ?int
    {
        $record = $actor['record_id'] ?? null; $file = $actor['content_file'] ?? null; $index = $actor['refnum']['index'] ?? null;
        if (!in_array($actor['kind'] ?? null, ['npc', 'creature'], true) || !is_string($record) || !is_string($file) || !is_int($index)) return null;
        $key = strtolower($record . '|' . $file) . '|' . $index;
        if (array_key_exists($key, $this->resolved)) return $this->resolved[$key];
        $query = $this->db->prepare("SELECT metadata.npc_id FROM lorkhan_internal.npc_metadata metadata
            JOIN lorkhan_internal.profiles profile ON profile.profile_id = metadata.source_profile_id
            WHERE metadata.installation_id = :installation AND profile.installation_id = :installation AND profile.deleted_at IS NULL
              AND lower(metadata.actor_identity->>'record_id') = lower(:record) AND lower(metadata.actor_identity->>'content_file') = lower(:file)
              AND metadata.actor_identity#>>'{refnum,index}' = :index AND " . ProfileScopeSql::current('profile') . ' LIMIT 2');
        $query->execute(['installation' => $this->installation, 'record' => $record, 'file' => $file, 'index' => (string) $index]);
        $rows = $query->fetchAll(PDO::FETCH_COLUMN);
        return $this->resolved[$key] = count($rows) === 1 ? (int) $rows[0] : null;
    }
}
