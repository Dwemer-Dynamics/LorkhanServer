<?php

declare(strict_types=1);
namespace LorkhanServer\Application;

use LorkhanServer\Infrastructure\PluginRuntimeRepository;
use LorkhanServer\Infrastructure\Uuid;

/** Deliver one persisted client plugin event to its trusted server hook, outside any database transaction. */
final class PluginEventJobHandler implements JobHandler
{
    public const TYPE = PluginRuntimeRepository::EVENT_JOB;
    public function __construct(private readonly PluginHooks $hooks) {}
    public function supports(string $type, int $version): bool { return $type === self::TYPE && $version === 1; }
    public function handle(array $payload, string $idempotencyKey, callable $heartbeat): void
    {
        unset($payload['_job']);
        $keys = array_keys($payload); sort($keys);
        if ($keys !== ['installation_id', 'message_id'] || !Uuid::isValid((string) $payload['installation_id'])
            || !Uuid::isValid((string) $payload['message_id']) || $idempotencyKey !== $payload['installation_id'] . '|' . $payload['message_id']) {
            throw new \InvalidArgumentException('invalid_plugin_event_job');
        }
        if (!$heartbeat()) throw new \RuntimeException('lease_lost');
        $this->hooks->dispatchEvent($payload['installation_id'], $payload['message_id']);
    }
}
