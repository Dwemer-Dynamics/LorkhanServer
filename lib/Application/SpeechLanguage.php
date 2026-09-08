<?php

declare(strict_types=1);
namespace LorkhanServer\Application;

/** Keep optional model language metadata bounded and separate from visible dialogue. */
final class SpeechLanguage
{
    public const CODES = ['en','es','fr','de','it','pt','pl','tr','ru','nl','cs','ar','zh-cn','ja','hu','ko','hi'];

    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 16) return null;
        $value = strtolower(trim($value));
        $value = match ($value) { 'jp'=>'ja', 'zh'=>'zh-cn', default=>$value };
        return in_array($value, self::CODES, true) ? $value : null;
    }

    /** Read only a leading language field; never infer metadata from quoted dialogue text. */
    public static function fromJsonPrefix(string $json): ?string
    {
        if (preg_match('/^\s*\{\s*"language"\s*:\s*"([a-zA-Z-]{1,16})"\s*,/D', $json, $match) !== 1) return null;
        return self::normalize($match[1]);
    }

    /** Optional durable-job fields; legacy jobs remain byte-for-byte unchanged. */
    public static function payload(mixed $value): array
    {
        $language = self::normalize($value);
        return $language === null ? [] : ['tts_language'=>$language];
    }

    public static function context(array $context, ?array $preset, mixed $value): array
    {
        if (!in_array($preset['content']['driver'] ?? '', ['xtts','xtts-fastapi','chatterbox'], true)) return $context;
        $language = self::normalize($value);
        if ($language !== null) $context['language'] = $language;
        return $context;
    }
}
