<?php

declare(strict_types=1);

namespace LorkhanServer\Tests\Support;

use RuntimeException;

final class StateStore
{
    private string $file;

    public function __construct(string $storagePath)
    {
        if (!is_dir($storagePath) && !mkdir($storagePath, 0700, true) && !is_dir($storagePath)) {
            throw new RuntimeException('Unable to create storage path.');
        }
        $this->file = rtrim($storagePath, '/') . '/development-state.json';
        if (!is_file($this->file)) {
            $this->save(['sessions' => [], 'events' => [], 'idempotency' => [], 'actions' => []]);
        }
    }

    /** @return array<string, mixed> */
    public function load(): array
    {
        $handle = fopen($this->file, 'c+');
        if ($handle === false || !flock($handle, LOCK_SH)) {
            throw new RuntimeException('Unable to read state.');
        }
        try {
            $json = stream_get_contents($handle);
            $value = json_decode($json ?: '{}', true, 64, JSON_THROW_ON_ERROR);
            return is_array($value) ? $value : [];
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @template T @param callable(array<string, mixed>&): T $operation @return T */
    public function mutate(callable $operation): mixed
    {
        $handle = fopen($this->file, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('Unable to lock state.');
        }
        try {
            $json = stream_get_contents($handle);
            $state = json_decode($json ?: '{}', true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($state)) {
                throw new RuntimeException('State is corrupt.');
            }
            $result = $operation($state);
            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            fflush($handle);
            return $result;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param array<string, mixed> $state */
    private function save(array $state): void
    {
        file_put_contents($this->file, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX);
        chmod($this->file, 0600);
    }
}
