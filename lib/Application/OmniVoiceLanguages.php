<?php
declare(strict_types=1);

namespace LorkhanServer\Application;

/** Read the local OmniVoice profile catalogue without contacting or changing the service. */
final class OmniVoiceLanguages
{
    public static function available(string $directory = '/home/dwemer/omnivoice-tts/languages'): array
    {
        $root = realpath($directory);
        if ($root === false || !is_dir($root) || !is_readable($root)) return [];
        $labels = [];
        foreach (array_slice(scandir($root) ?: [], 0, 256) as $entry) {
            if (!str_ends_with($entry, '.json')) continue;
            $path = $root . DIRECTORY_SEPARATOR . $entry;
            // Never follow profile symlinks into unrelated files or read unbounded payloads.
            if (is_link($path) || !is_file($path) || !is_readable($path) || filesize($path) > 65536) continue;
            $raw = @file_get_contents($path);
            if (!is_string($raw) || stripos($raw, 'REPLACE THIS') !== false) continue;
            $profile = json_decode($raw, true);
            if (!is_array($profile)) continue;
            $id = $profile['id'] ?? pathinfo($entry, PATHINFO_FILENAME);
            if (!is_string($id)) continue;
            $id = strtolower(trim($id));
            if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/D', $id) !== 1) continue;
            $label = $profile['display_name'] ?? $profile['omnivoice_language'] ?? strtoupper($id);
            if (!is_string($label)) continue;
            $label = trim($label);
            $labels[$id] = mb_substr($label !== '' ? $label : strtoupper($id), 0, 128) . ' (' . $id . ')';
        }
        asort($labels, SORT_NATURAL | SORT_FLAG_CASE);
        return $labels;
    }
}
