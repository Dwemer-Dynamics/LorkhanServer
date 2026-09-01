<?php

declare(strict_types=1);

namespace LorkhanServer\Tests\Support;

use LorkhanServer\Application\CancellationToken;
use LorkhanServer\Application\MockProvider;
use LorkhanServer\Application\Provider;
use RuntimeException;

final class ControllableProvider implements Provider
{
    public function __construct(private readonly string $controlDirectory) {}

    public function complete(array $turn, CancellationToken $cancellation): array
    {
        $cancellation->throwIfCancellationRequested();
        $text = (string) ($turn['payload']['input']['text'] ?? $turn['turn']['text'] ?? '');
        if (str_contains($text, '[provider-fail]')) {
            throw new RuntimeException('controlled provider failure');
        }
        if (str_contains($text, '[provider-slow]')) {
            usleep(300_000);
        }
        if (str_contains($text, '[provider-block]')) {
            $turnId = (string) ($turn['turn_id'] ?? '');
            $ready = $this->controlDirectory . '/' . $turnId . '.ready';
            $release = $this->controlDirectory . '/' . $turnId . '.release';
            if (file_put_contents($ready, "ready\n", LOCK_EX) === false) {
                throw new RuntimeException('controlled provider marker failed');
            }
            $deadline = microtime(true) + 10.0;
            while (!is_file($release)) {
                $cancellation->throwIfCancellationRequested();
                if (microtime(true) >= $deadline) {
                    throw new RuntimeException('controlled provider release timed out');
                }
                usleep(10_000);
            }
        }
        $cancellation->throwIfCancellationRequested();
        return (new MockProvider())->complete($turn, $cancellation);
    }
}
