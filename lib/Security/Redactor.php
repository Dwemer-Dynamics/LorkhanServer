<?php

declare(strict_types=1);

namespace LorkhanServer\Security;

final class Redactor
{
    private const SENSITIVE_KEYS = [
        'authorization', 'pairing_token', 'provider_key', 'api_key', 'password', 'secret', 'token',
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
        if (is_string($value)) {
            return preg_replace('/Bearer\s+[A-Za-z0-9._~+\/-]+/i', 'Bearer [REDACTED]', $value);
        }
        return $value;
    }
}
