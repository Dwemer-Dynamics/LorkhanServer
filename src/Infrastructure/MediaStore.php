<?php
declare(strict_types=1);

namespace LORKHANserver\Infrastructure;

use RuntimeException;

final class MediaStore
{
    public function __construct(
        private readonly string $root,
        private readonly int $maxBytes = 33_554_432,
        private readonly int $quotaBytes = 268_435_456,
    ) {
        if ($root === '' || $maxBytes < 1 || $quotaBytes < $maxBytes) throw new RuntimeException('Invalid media limits.');
    }

    public function put(string $mediaId, string $bytes, string $codec, string $mimeType): string
    {
        $this->id($mediaId);
        $size = strlen($bytes);
        if ($size < 1 || $size > $this->maxBytes) throw new RuntimeException('media_size_invalid');
        $valid = match ($codec . '|' . $mimeType) {
            'wav|audio/wav' => $size >= 44 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WAVE',
            'ogg|audio/ogg' => $size >= 4 && substr($bytes, 0, 4) === 'OggS',
            'mp3|audio/mpeg' => $size >= 3 && (substr($bytes, 0, 3) === 'ID3' || (ord($bytes[0]) === 0xff && (ord($bytes[1]) & 0xe0) === 0xe0)),
            default => false,
        };
        if (!$valid) throw new RuntimeException('media_type_invalid');
        $this->ensureRoot();
        if ($this->usedBytes() + $size > $this->quotaBytes) throw new RuntimeException('media_quota_exceeded');
        $path = $this->path($mediaId);
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = fopen($temporary, 'x+b');
        if ($handle === false) throw new RuntimeException('media_write_failed');
        try {
            if (!flock($handle, LOCK_EX) || fwrite($handle, $bytes) !== $size || !fflush($handle)) {
                throw new RuntimeException('media_write_failed');
            }
        // The worker writes media and the Apache service reads it through their private shared group.
        @chmod($temporary, 0640);
        } finally {
            fclose($handle);
        }
        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('media_write_failed');
        }
        return hash('sha256', $bytes);
    }

    public function read(string $mediaId, int $expectedBytes, string $expectedHash): string
    {
        $path = $this->path($mediaId);
        if (!is_file($path) || is_link($path)) throw new RuntimeException('media_unavailable');
        $bytes = file_get_contents($path);
        if ($bytes === false || strlen($bytes) !== $expectedBytes || !hash_equals($expectedHash, hash('sha256', $bytes))) {
            throw new RuntimeException('media_integrity_failed');
        }
        return $bytes;
    }

    public function delete(string $mediaId): void
    {
        $path = $this->path($mediaId);
        if (is_file($path) && !is_link($path)) @unlink($path);
    }

    private function path(string $mediaId): string
    {
        $this->id($mediaId);
        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $mediaId . '.media';
    }

    private function id(string $mediaId): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $mediaId) !== 1) {
            throw new RuntimeException('media_unavailable');
        }
    }

    private function ensureRoot(): void
    {
        if (!is_dir($this->root) && !mkdir($this->root, 0700, true) && !is_dir($this->root)) throw new RuntimeException('media_storage_unavailable');
        @chmod($this->root, 02770);
        $real = realpath($this->root);
        $public = realpath(dirname(__DIR__, 2) . '/public');
        if ($real === false || is_link($this->root) || ($public !== false && ($real === $public || str_starts_with($real, $public . DIRECTORY_SEPARATOR)))) {
            throw new RuntimeException('media_storage_unsafe');
        }
    }

    private function usedBytes(): int
    {
        $total = 0;
        foreach (glob(rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*.media') ?: [] as $file) {
            if (is_file($file) && !is_link($file)) $total += filesize($file) ?: 0;
        }
        return $total;
    }
}
