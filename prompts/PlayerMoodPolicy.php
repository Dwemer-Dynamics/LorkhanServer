<?php
declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;

final class PlayerMoodPolicy
{
    private const TEMPLATES = [
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
        'custom' => '(speaks {CUSTOM_MOOD}.)',
    ];

    public static function defaultTemplates(): array
    {
        return self::TEMPLATES;
    }

    /** Validate the complete revision-owned template map before it can reach prompts or history. */
    public static function validateTemplates(mixed $templates): array
    {
        if (!is_array($templates) || array_is_list($templates)) throw new InvalidArgumentException('invalid_player_mood_prompts');
        $keys = array_keys($templates);
        sort($keys);
        $expected = array_keys(self::TEMPLATES);
        sort($expected);
        if ($keys !== $expected) throw new InvalidArgumentException('invalid_player_mood_prompts');
        foreach ($templates as $key => $template) {
            if (!is_string($template) || !mb_check_encoding($template, 'UTF-8') || mb_strlen($template, 'UTF-8') > 512
                || str_contains($template, "\n") || str_contains($template, "\r") || str_contains($template, "\0")) {
                throw new InvalidArgumentException('invalid_player_mood_prompt_' . $key);
            }
            preg_match_all('/\{[A-Z_]+\}/', $template, $matches);
            if (array_diff(array_unique($matches[0] ?? []), ['{PLAYER_NAME}','{MOOD}','{CUSTOM_MOOD}']) !== []) {
                throw new InvalidArgumentException('invalid_player_mood_prompt_' . $key);
            }
        }
        return $templates;
    }

    /** Resolve validated mood data through a revision-owned template with current defaults as fallback. */
    public static function cue(mixed $mood, mixed $templates = null, string $playerName = 'Player'): string
    {
        if (!is_array($mood) || array_is_list($mood)) return '';
        $kind = $mood['kind'] ?? null;
        if (!is_string($kind) || !array_key_exists($kind, self::TEMPLATES)) return '';
        $custom = '';
        if ($kind === 'custom') {
            $custom = trim((string) ($mood['custom'] ?? ''));
            if ($custom === '' || str_contains($custom, "\n") || str_contains($custom, "\r")
                || mb_strlen($custom, 'UTF-8') > 80) return '';
        }
        try {
            $resolvedTemplates = $templates === null ? self::TEMPLATES : self::validateTemplates($templates);
        } catch (InvalidArgumentException) {
            $resolvedTemplates = self::TEMPLATES;
        }
        $template = trim($resolvedTemplates[$kind]);
        if ($template === '') $template = self::TEMPLATES[$kind];
        $playerName = trim($playerName);
        if ($playerName === '') $playerName = 'Player';
        $resolved = strtr($template, [
            '{PLAYER_NAME}'=>mb_substr($playerName, 0, 128, 'UTF-8'),
            '{MOOD}'=>$kind,
            '{CUSTOM_MOOD}'=>$custom,
        ]);
        return mb_substr(trim($resolved), 0, 1024, 'UTF-8');
    }

    public static function decorate(string $text, mixed $mood, mixed $templates = null, string $playerName = 'Player'): string
    {
        $cue = self::cue($mood, $templates, $playerName);
        return self::decorateWithCue($text, $cue);
    }

    public static function decorateWithCue(string $text, mixed $cue): string
    {
        $cue = is_string($cue) && mb_check_encoding($cue, 'UTF-8') ? trim($cue) : '';
        return $cue === '' ? $text : $text . ' ' . $cue;
    }
}
