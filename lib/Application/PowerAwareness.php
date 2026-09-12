<?php
declare(strict_types=1);
namespace LorkhanServer\Application;

/** Descriptors and thresholds from HerikaServer 529364c lib/power_awareness.php. */
final class PowerAwareness
{
    public static function describe(mixed $assessor, mixed $target): string
    {
        foreach ([$assessor,$target] as $level) {
            if ((!is_int($level) && !is_float($level)) || !is_finite((float)$level)
                || $level < 1 || $level > 1000000 || floor((float)$level) !== (float)$level) return '';
        }
        return match (true) {
            $target-$assessor >= 10 => 'appears overwhelmingly powerful',
            $target-$assessor >= 5 => 'appears considerably stronger',
            $target-$assessor >= 2 => 'appears somewhat stronger',
            $target-$assessor >= -1 => 'appears evenly matched',
            $target-$assessor >= -4 => 'appears somewhat weaker',
            $target-$assessor >= -9 => 'appears considerably weaker',
            default => 'appears far beneath you',
        };
    }
}
