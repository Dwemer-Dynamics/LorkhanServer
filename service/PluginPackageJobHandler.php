<?php

declare(strict_types=1);
namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\PluginPackageRepository;
use PDO;

/** Extract and activate one queued .dwpkg operation. Validation failures finish the operation instead of retrying. */
final class PluginPackageJobHandler implements JobHandler
{
    public const TYPE = PluginPackageRepository::JOB_TYPE;
    public function __construct(private readonly PDO $db, private readonly array $config) {}
    public function supports(string $type, int $version): bool { return $type === self::TYPE && $version === 1; }
    public function handle(array $payload, string $idempotencyKey, callable $heartbeat): void
    {
        $job = $payload['_job'] ?? []; unset($payload['_job']);
        $keys = array_keys($payload); sort($keys);
        if ($keys !== ['installation_id', 'operation_id', 'upload_id'] || ($payload['operation_id'] ?? null) !== $idempotencyKey) {
            throw new \InvalidArgumentException('invalid_plugin_package_job');
        }
        if (!$heartbeat()) throw new \RuntimeException('lease_lost');
        $version = trim((string) @file_get_contents(dirname(__DIR__) . '/version.txt'));
        (new PluginPackageRepository($this->db, $this->config))->apply($payload, $version,
            (int) ($job['attempt'] ?? 1) >= (int) ($job['max_attempts'] ?? 1));
    }
}
