<?php

declare(strict_types=1);

namespace ALMSIVIserver\Application;

use ALMSIVIserver\Infrastructure\FirstPartyJobRepository;
use ALMSIVIserver\Infrastructure\MediaStore;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

abstract class FirstPartyJobHandler implements JobHandler
{
    public function __construct(protected readonly FirstPartyJobRepository $repository, protected readonly DeterministicClock $clock) {}

    protected function heartbeat(callable $heartbeat): void
    {
        if (!$heartbeat()) {
            throw new RuntimeException('lease_lost');
        }
    }

    /** @param array<string,mixed> $payload @return array{installation_id:string,profile_id:string,playthrough_id:string} */
    protected function scope(array $payload): array
    {
        return [
            'installation_id' => $this->uuid($payload, 'installation_id'),
            'profile_id' => $this->uuid($payload, 'profile_id'),
            'playthrough_id' => $this->uuid($payload, 'playthrough_id'),
        ];
    }

    /** @param array<string,mixed> $payload */
    protected function uuid(array $payload, string $field): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1) {
            throw new InvalidArgumentException('invalid_' . $field);
        }
        return $value;
    }

    /** @param array<string,mixed> $payload */
    protected function text(array $payload, string $field, int $max): string
    {
        $value = $payload[$field] ?? null;
        if (!is_string($value) || $value === '' || strlen($value) > $max || !mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidArgumentException('invalid_' . $field);
        }
        return $value;
    }

    /** @param array<string,mixed> $payload */
    protected function timestamp(array $payload, string $field, ?string $default = null): string
    {
        $value = $payload[$field] ?? $default;
        if (!is_string($value)) {
            throw new InvalidArgumentException('invalid_' . $field);
        }
        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            throw new InvalidArgumentException('invalid_' . $field);
        }
    }

    /** @param array<string,mixed> $payload */
    protected function limit(array $payload, int $default = 100): int
    {
        $limit = $payload['limit'] ?? $default;
        if (!is_int($limit) || $limit < 1 || $limit > 500) {
            throw new InvalidArgumentException('invalid_limit');
        }
        return $limit;
    }
}

final class MemoryDeriveJobHandler extends FirstPartyJobHandler
{
    public const TYPE = 'memory.derive';

    public function supports(string $jobType, int $schemaVersion): bool
    {
        return $jobType === self::TYPE && $schemaVersion === 1;
    }

    public function handle(array $payload, string $idempotencyKey, callable $heartbeat): void
    {
        $this->heartbeat($heartbeat);
        $memoryId = $this->uuid($payload, 'memory_id');
        $memory = $this->scope($payload) + [
            'tier' => $this->text($payload, 'tier', 16),
            'content' => $this->text($payload, 'content', 16384),
            'provenance' => $payload['provenance'] ?? ['source' => self::TYPE, 'idempotency_key' => $idempotencyKey],
        ];
        if (!in_array($memory['tier'], ['recent', 'mid', 'long'], true) || !is_array($memory['provenance'])
            || array_is_list($memory['provenance']) || !is_string($memory['provenance']['source'] ?? null)
            || $memory['provenance']['source'] === '') {
            throw new InvalidArgumentException('invalid_memory_payload');
        }
        foreach (['source_event_id', 'occurred_at', 'expires_at'] as $optional) {
            if (array_key_exists($optional, $payload)) {
                $memory[$optional] = $optional === 'source_event_id' ? $this->uuid($payload, $optional) : $this->timestamp($payload, $optional);
            }
        }
        $this->repository->assertMemorySourceEligible($memory);
        $this->repository->upsertMemory($memoryId, $memory, $this->clock->iso());
    }
}

final class MemoryRebuildJobHandler extends FirstPartyJobHandler
{
    public const TYPE = 'memory.rebuild';

    public function supports(string $jobType, int $schemaVersion): bool
    {
        return $jobType === self::TYPE && $schemaVersion === 1;
    }

    public function handle(array $payload, string $idempotencyKey, callable $heartbeat): void
    {
        $this->heartbeat($heartbeat);
        $after = $payload['after_memory_id'] ?? null;
        if ($after !== null) {
            $after = $this->uuid($payload, 'after_memory_id');
        }
        $this->repository->rebuildMemories($this->scope($payload), $after, $this->limit($payload), $this->clock->iso());
    }
}

final class NarrativeJobHandler extends FirstPartyJobHandler
{
    public const SUMMARY_TYPE = 'narrative.summary';
    public const DIARY_TYPE = 'narrative.diary';

    public function supports(string $jobType, int $schemaVersion): bool
    {
        return in_array($jobType, [self::SUMMARY_TYPE, self::DIARY_TYPE], true) && $schemaVersion === 1;
    }

    public function handle(array $payload, string $idempotencyKey, callable $heartbeat): void
    {
        $this->heartbeat($heartbeat);
        $kind = $this->text($payload, 'kind', 16);
        if (!in_array($kind, ['summary', 'diary'], true)) {
            throw new InvalidArgumentException('invalid_narrative_kind');
        }
        $provenance = $payload['provenance'] ?? ['source' => 'narrative.' . $kind, 'idempotency_key' => $idempotencyKey];
        if (!is_array($provenance) || array_is_list($provenance) || !is_string($provenance['source'] ?? null) || $provenance['source'] === '') {
            throw new InvalidArgumentException('invalid_provenance');
        }
        $this->repository->upsertNarrative($this->uuid($payload, 'narrative_id'), $this->scope($payload) + [
            'kind' => $kind,
            'title' => $this->text($payload, 'title', 256),
            'content' => $this->text($payload, 'content', 65536),
            'provenance' => $provenance,
        ], $this->clock->iso());
    }
}

final class MediaCleanupJobHandler extends FirstPartyJobHandler
{
    public const TYPE = 'media.cleanup';

    public function __construct(FirstPartyJobRepository $repository, DeterministicClock $clock, private readonly MediaStore $mediaStore)
    {
        parent::__construct($repository, $clock);
    }

    public function supports(string $jobType, int $schemaVersion): bool
    {
        return $jobType === self::TYPE && $schemaVersion === 1;
    }

    public function handle(array $payload, string $idempotencyKey, callable $heartbeat): void
    {
        $installation = array_key_exists('installation_id', $payload) ? $this->uuid($payload, 'installation_id') : null;
        $before = $this->timestamp($payload, 'expired_before', $this->clock->iso());
        foreach ($this->repository->cleanupMediaCandidates($installation, $before, $this->limit($payload)) as $mediaId) {
            $this->heartbeat($heartbeat);
            $this->mediaStore->delete($mediaId);
            $this->repository->markMediaDeleted($mediaId, $this->clock->iso());
        }
    }
}

final class RetentionJobHandler extends FirstPartyJobHandler
{
    public const TYPE = 'retention.enforce';

    public function supports(string $jobType, int $schemaVersion): bool
    {
        return $jobType === self::TYPE && $schemaVersion === 1;
    }

    public function handle(array $payload, string $idempotencyKey, callable $heartbeat): void
    {
        $this->heartbeat($heartbeat);
        $limit = $this->limit($payload);
        $now = $this->timestamp($payload, 'as_of', $this->clock->iso());
        if (isset($payload['memory_days'])) {
            if (!is_array($payload['memory_days']) || array_is_list($payload['memory_days'])) {
                throw new InvalidArgumentException('invalid_memory_days');
            }
            $days = [];
            foreach (['recent', 'mid', 'long'] as $tier) {
                $value = $payload['memory_days'][$tier] ?? 0;
                if (!is_int($value) || $value < 0 || $value > 36500) {
                    throw new InvalidArgumentException('invalid_memory_days');
                }
                $days[$tier] = $value;
            }
            $this->repository->retainMemories($this->scope($payload), $days, $now, $limit);
            return;
        }
        $days = $payload['days'] ?? 30;
        if (!is_int($days) || $days < 1 || $days > 36500) {
            throw new InvalidArgumentException('invalid_days');
        }
        $this->repository->retainOperational($days, $now, $limit);
    }
}

final class ProviderReconciliationJobHandler extends FirstPartyJobHandler
{
    public const TYPE = 'provider.reconcile';

    public function supports(string $jobType, int $schemaVersion): bool
    {
        return $jobType === self::TYPE && $schemaVersion === 1;
    }

    public function handle(array $payload, string $idempotencyKey, callable $heartbeat): void
    {
        $this->heartbeat($heartbeat);
        if (array_key_exists('provider_attempt_id', $payload)) {
            $state = $this->text($payload, 'state', 16);
            if (!in_array($state, ['succeeded', 'failed', 'cancelled'], true)) {
                throw new InvalidArgumentException('invalid_provider_state');
            }
            $output = $payload['output_bytes'] ?? null;
            if ($output !== null && (!is_int($output) || $output < 0)) {
                throw new InvalidArgumentException('invalid_output_bytes');
            }
            $code = $payload['error_code'] ?? null;
            if ($code !== null && (!is_string($code) || $code === '' || strlen($code) > 255)) {
                throw new InvalidArgumentException('invalid_error_code');
            }
            if ($state === 'succeeded' && $code !== null) {
                throw new InvalidArgumentException('invalid_provider_result');
            }
            $this->repository->reconcileProviderAttempt($this->uuid($payload, 'provider_attempt_id'), $state, $output, $code);
            return;
        }
        $this->repository->reconcileStaleProviderAttempts(
            $this->timestamp($payload, 'started_before', $this->clock->iso()),
            $this->limit($payload),
        );
    }
}
