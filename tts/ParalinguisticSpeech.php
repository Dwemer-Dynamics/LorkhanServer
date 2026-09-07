<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

/** Share connector-selected expressive speech rules between prompt assembly and synthesis. */
final class ParalinguisticSpeech
{
    public const DEFAULT_TAGS = '[clear throat],[sigh],[shush],[cough],[groan],[sniff],[gasp],[chuckle],[laugh]';

    public static function prompt(array $content): string
    {
        if (!in_array($content['driver'] ?? '', ['chatterbox','xtts-fastapi'], true)) return '';
        $options = $content['options'] ?? [];
        if (($options['paralinguistic_tags_enabled'] ?? false) !== true) return '';
        return trim((string)($options['paralinguistic_tags_prompt'] ?? ''));
    }

    /** Filter provider-only bracket cues; absent settings retain previously deployed behavior. */
    public static function speech(string $text, array $options): string
    {
        if (!array_key_exists('paralinguistic_tags_enabled', $options)) return $text;
        $list = trim((string)($options['paralinguistic_tags_list'] ?? ''));
        $tags = ($options['paralinguistic_tags_enabled'] ?? false) === true
            ? array_map(static fn(string $tag): string => strtolower(trim($tag)), explode(',', $list === '' ? self::DEFAULT_TAGS : $list)) : [];
        return trim(preg_replace_callback('/\[[^\]\r\n]*\]/u', static fn(array $match): string =>
            in_array(strtolower($match[0]), $tags, true) ? $match[0] : '', $text) ?? $text);
    }
}
