<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

interface JobHandler
{
    public function supports(string $jobType, int $schemaVersion): bool;

    /**
     * Implementations must be idempotent for the job's idempotency key and keep each invocation bounded.
     * The callback renews the lease and returns false if ownership has been lost.
     *
     * @param array<string,mixed> $payload
     * @param callable():bool $heartbeat
     */
    public function handle(array $payload, string $idempotencyKey, callable $heartbeat): void;
}
