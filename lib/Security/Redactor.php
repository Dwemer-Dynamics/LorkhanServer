<?php

declare(strict_types=1);

namespace LorkhanServer\Security;

final class Redactor
{
    private const SENSITIVE_KEYS = [
        'authorization', 'pairing_token', 'provider_key', 'api_key', 'password', 'secret', 'token',
        'access_token', 'client_secret', 'cookie', 'set-cookie',
    ];

    /** @param mixed $value @return mixed */
    public static function value(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && in_array(strtolower($key), self::SENSITIVE_KEYS, true)) {
            return '[REDACTED]';
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $childKey => $child) {
                $result[$childKey] = self::value($child, is_string($childKey) ? $childKey : null);
            }
            return $result;
        }
        if ($value instanceof \stdClass) {
            $result = clone $value;
            foreach (get_object_vars($value) as $childKey => $child) $result->$childKey = self::value($child, $childKey);
            return $result;
        }
        if (is_string($value)) {
            $value = preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+/i', 'Bearer [REDACTED]', $value) ?? $value;
            // Match the Dashboard's credential scrubber before diagnostic text reaches disk.
            $keys = 'authorization|(?:provider[_-]?)?api[_-]?key|provider[_-]?key|access[_-]?token|pairing[_-]?token|client[_-]?secret|secret|password|token|cookie|set-cookie';
            $value = preg_replace_callback('/((?:"?(?:'.$keys.')"?)\s*[:=]\s*)("(?:\\\\.|[^"\\\\])*"|\x27[^\x27]*\x27|(?:Bearer|Basic)\s+[^\s,;]+|[^\s,;]+)/i',
                static fn(array $match): string => $match[1].(str_starts_with($match[2], '"') ? '"[REDACTED]"' : '[REDACTED]'), $value) ?? $value;
            return preg_replace('/([?&](?:key|'.$keys.')=)[^&\s"\x27]+/i', '$1[REDACTED]', $value) ?? $value;
        }
        return $value;
    }
}
