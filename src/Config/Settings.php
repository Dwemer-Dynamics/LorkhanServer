<?php

declare(strict_types=1);

namespace LORKHANserver\Config;

use InvalidArgumentException;

final readonly class Settings
{
    public function __construct(
        public string $pairingTokenHash,
        public string $storagePath,
        public int $maxJsonBytes = 2_097_152,
        public int $maxContextBytes = 131_072,
        public int $eventReplayLimit = 256,
        public int $rateLimitRequests = 120,
        public int $rateLimitWindowSeconds = 60,
    ) {
        if (preg_match('/^[0-9a-f]{64}$/D', $pairingTokenHash) !== 1) {
            throw new InvalidArgumentException('A SHA-256 pairing token hash is required.');
        }
        if ($storagePath === '' || $maxJsonBytes < 1024 || $maxContextBytes < 1024 || $maxContextBytes > $maxJsonBytes
            || $eventReplayLimit < 1 || $eventReplayLimit > 1000 || $rateLimitRequests < 1 || $rateLimitWindowSeconds < 1) {
            throw new InvalidArgumentException('Configured limits are invalid.');
        }
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self(
            pairingTokenHash: (string) ($values['pairing_token_hash'] ?? ''),
            storagePath: (string) ($values['storage_path'] ?? dirname(__DIR__, 2) . '/storage'),
            maxJsonBytes: (int) ($values['max_json_bytes'] ?? 2_097_152),
            maxContextBytes: (int) ($values['max_context_bytes'] ?? 131_072),
            eventReplayLimit: (int) ($values['event_replay_limit'] ?? 256),
            rateLimitRequests: (int) ($values['rate_limit_requests'] ?? 120),
            rateLimitWindowSeconds: (int) ($values['rate_limit_window_seconds'] ?? 60),
        );
    }
}
