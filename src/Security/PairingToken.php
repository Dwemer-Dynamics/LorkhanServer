<?php

declare(strict_types=1);

namespace LORKHANserver\Security;

final class PairingToken
{
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function verifyAuthorization(?string $authorization, string $expectedHash): bool
    {
        if ($authorization === null || !str_starts_with($authorization, 'Bearer ')) {
            return false;
        }
        $token = substr($authorization, 7);
        return $token !== '' && hash_equals($expectedHash, self::hash($token));
    }

    public static function fingerprint(string $token): string
    {
        return substr(self::hash($token), 0, 12);
    }
}
