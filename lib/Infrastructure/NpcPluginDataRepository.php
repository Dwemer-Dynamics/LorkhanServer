<?php
declare(strict_types=1);

namespace LorkhanServer\Infrastructure;

use InvalidArgumentException;
use PDO;
use stdClass;
use UnexpectedValueException;

/** Namespaced NPC state scoped to one installation and its current playthrough. */
final class NpcPluginDataRepository
{
    public function __construct(private readonly PDO $db, private readonly string $installation)
    {
        if (!Uuid::isValid($installation)) throw new InvalidArgumentException('invalid_installation');
    }

    // Resolve public NPC IDs through their typed owner; names are never identity keys.
    private function scope(int $npcId, string $pluginId): string
    {
        if ($npcId <= 0 || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $pluginId)) {
            throw new InvalidArgumentException('A positive NPC ID and a lowercase plugin ID are required.');
        }
        return 'metadata.npc_id = :npc AND metadata.source_profile_id = profile.profile_id
            AND profile.installation_id = :installation AND profile.deleted_at IS NULL AND '
            . ProfileScopeSql::current('profile');
    }

    public function getPluginData(int $npcId, string $pluginId): ?array
    {
        $scope = $this->scope($npcId, $pluginId);
        $query = $this->db->prepare('SELECT profile.plugin_extended_data -> CAST(:plugin AS text)
            FROM lorkhan_internal.profiles profile, lorkhan_internal.npc_metadata metadata WHERE ' . $scope);
        $query->execute(['npc'=>$npcId, 'plugin'=>$pluginId, 'installation'=>$this->installation]);
        $json = $query->fetchColumn();
        if ($json === false || $json === null) return null;
        $data = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        if (!$data instanceof stdClass) throw new UnexpectedValueException('Stored plugin data must be a JSON object.');
        return get_object_vars($data);
    }

    // The row lock and SQL JSON update preserve simultaneous writes from other plugins.
    public function setPluginData(int $npcId, string $pluginId, array $data): bool
    {
        $scope = $this->scope($npcId, $pluginId);
        foreach (array_keys($data) as $key) {
            if (!is_string($key)) throw new InvalidArgumentException('Plugin data must have string object keys.');
        }
        $json = json_encode((object)$data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $query = $this->db->prepare('UPDATE lorkhan_internal.profiles profile
            SET plugin_extended_data = jsonb_set(profile.plugin_extended_data, ARRAY[CAST(:plugin AS text)], CAST(:data AS jsonb), true)
            FROM lorkhan_internal.npc_metadata metadata WHERE ' . $scope . ' RETURNING profile.profile_id');
        $query->execute(['npc'=>$npcId, 'plugin'=>$pluginId, 'data'=>$json, 'installation'=>$this->installation]);
        return $query->fetchColumn() !== false;
    }

    public function deletePluginData(int $npcId, string $pluginId): bool
    {
        $scope = $this->scope($npcId, $pluginId);
        $query = $this->db->prepare('UPDATE lorkhan_internal.profiles profile
            SET plugin_extended_data = profile.plugin_extended_data - CAST(:plugin AS text)
            FROM lorkhan_internal.npc_metadata metadata WHERE ' . $scope . ' RETURNING profile.profile_id');
        $query->execute(['npc'=>$npcId, 'plugin'=>$pluginId, 'installation'=>$this->installation]);
        return $query->fetchColumn() !== false;
    }
}
