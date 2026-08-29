<?php
declare(strict_types=1);

namespace ALMSIVIserver\Application;

final class PlayerMoodPolicy
{
    private const CUES = [
        'happy' => '(speaks in a happy tone.)',
        'sad' => '(speaks in a sad tone.)',
        'angry' => '(speaks in an angry tone.)',
        'annoyed' => '(speaks in an annoyed tone.)',
        'scared' => '(speaks in a frightened tone.)',
        'surprised' => '(speaks in a surprised tone.)',
        'confused' => '(speaks in a confused tone.)',
        'suspicious' => '(speaks in a suspicious tone.)',
        'playful' => '(speaks in a playful tone.)',
        'flirty' => '(speaks in a flirtatious tone.)',
    ];

    /** Resolve validated turn mood data to the short cue used in prompts and projected history. */
    public static function cue(mixed $mood): string
    {
        if (!is_array($mood) || array_is_list($mood)) return '';
        $kind = $mood['kind'] ?? null;
        if (!is_string($kind)) return '';
        if ($kind === 'custom') {
            $custom = trim((string) ($mood['custom'] ?? ''));
            return $custom === '' ? '' : '(speaks ' . $custom . '.)';
        }
        return self::CUES[$kind] ?? '';
    }

    public static function decorate(string $text, mixed $mood): string
    {
        $cue = self::cue($mood);
        return $cue === '' ? $text : $text . ' ' . $cue;
    }
}
