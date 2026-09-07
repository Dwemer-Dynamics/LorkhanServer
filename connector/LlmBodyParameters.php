<?php

declare(strict_types=1);

namespace LorkhanServer\Application;

use InvalidArgumentException;
use Symfony\Component\Yaml\Yaml;

/** Parse connector body extensions without enabling YAML object, file or constant evaluation. */
final class LlmBodyParameters
{
    public static function parse(string $yaml): array
    {
        if (strlen($yaml) > 16384 || !mb_check_encoding($yaml, 'UTF-8')) throw new InvalidArgumentException('invalid_provider_body_yaml');
        if (trim((string)preg_replace('/^\s*#.*$/m', '', $yaml)) === '') return [];
        // Symfony 7.x reports some duplicate null-valued keys as deprecations, not parse errors.
        set_error_handler(static function (): never { throw new InvalidArgumentException('invalid_provider_body_yaml'); }, E_USER_DEPRECATED);
        try {
            $body = Yaml::parse($yaml, Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE | Yaml::PARSE_EXCEPTION_ON_ALIAS | Yaml::PARSE_OBJECT_FOR_MAP, 16, 0);
            if (!$body instanceof \stdClass) throw new InvalidArgumentException('invalid_provider_body_yaml');
            $nodes = 0;
            self::validateValue($body, $nodes);
            $body = (array)$body;
            // Native messages and delivery shape must still describe the audited, validated operation.
            foreach (['messages', 'model', 'stream', 'tools', 'functions', 'tool_choice', 'function_call', 'n', 'modalities', 'audio'] as $key) {
                if (array_key_exists($key, $body)) throw new InvalidArgumentException('reserved_provider_body_parameter');
            }
            return $body;
        } catch (\Symfony\Component\Yaml\Exception\ParseException) {
            // Parser diagnostics can contain input text. Do not echo them into logs or error pages.
            throw new InvalidArgumentException('invalid_provider_body_yaml');
        } finally { restore_error_handler(); }
    }

    /** Bound the decoded JSON tree and keep credentials/transport configuration out of portable body data. */
    private static function validateValue(mixed $value, int &$nodes): void
    {
        if (++$nodes > 1024) throw new InvalidArgumentException('invalid_provider_body_yaml');
        if ($value instanceof \stdClass || is_array($value)) {
            foreach ($value as $key => $item) {
                if ($value instanceof \stdClass) {
                    if (!is_string($key) || strlen($key) > 128 || preg_match('/[\x00-\x1f\x7f]/', $key)) throw new InvalidArgumentException('invalid_provider_body_yaml');
                    if (preg_match('/^(api[_-]?key|authorization|credential|password|secret|access[_-]?token|headers|endpoint|url)$/iD', $key)) {
                        throw new InvalidArgumentException('reserved_provider_body_parameter');
                    }
                }
                self::validateValue($item, $nodes);
            }
        } elseif (!(is_string($value) || is_int($value) || is_bool($value) || $value === null || (is_float($value) && is_finite($value)))) {
            throw new InvalidArgumentException('invalid_provider_body_yaml');
        }
    }
}
