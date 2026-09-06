<?php
declare(strict_types=1);

namespace LorkhanServer\Application;

use RuntimeException;

/** Original, offline-synthesized speech shared by the connector test and its result reader. */
final class SttTestSample
{
    public const TEXT = 'Ash drifts across the quiet road. A traveler stops at the inn, warms by the fire, and asks the keeper for a room until morning.';
    public const SHA256 = '215045ef4637a7481bbf4c8800231a1308d72f81afddf5df678ed5c64ffa91da';

    public static function bytes(): string
    {
        $bytes = @file_get_contents(dirname(__DIR__) . '/ui/tests/assets/stt-test.wav');
        if (!is_string($bytes) || !hash_equals(self::SHA256, hash('sha256', $bytes))) {
            throw new RuntimeException('stt_test_sample_unavailable');
        }
        return $bytes;
    }

    /** Match Herika's case-insensitive edit-distance percentage; it is diagnostic, not a pass threshold. */
    public static function similarity(string $transcript): float
    {
        $expected = strtolower(self::TEXT);
        $actual = strtolower($transcript);
        return round(100 * (1 - levenshtein($expected, $actual) / max(strlen($expected), strlen($actual))), 2);
    }
}
