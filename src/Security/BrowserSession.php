<?php

declare(strict_types=1);

namespace LorkhanServer\Security;

final class BrowserSession
{
    public static function token(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    public static function hash(string $value): string { return hash('sha256', $value); }

    public static function cookie(string $token, int $maxAge,string $path='/LorkhanServer'): string
    {
        return 'lorkhan_management=' . rawurlencode($token) . '; Path='.$path.'; Max-Age=' . $maxAge
            . '; HttpOnly; SameSite=Strict';
    }

    public static function csrfCookie(string $token, int $maxAge,string $path='/LorkhanServer'): string
    {
        return 'lorkhan_csrf=' . rawurlencode($token) . '; Path='.$path.'; Max-Age=' . $maxAge . '; SameSite=Strict';
    }

    public static function clearCookies(): string
    {
        return 'lorkhan_management=; Path=/LorkhanServer; Max-Age=0; HttpOnly; SameSite=Strict, '
            . 'lorkhan_csrf=; Path=/LorkhanServer; Max-Age=0; SameSite=Strict';
    }

    public static function parse(?string $cookie): ?string
    {
        return self::parseNamed($cookie, 'lorkhan_management');
    }

    public static function parseCsrf(?string $cookie): ?string
    {
        return self::parseNamed($cookie, 'lorkhan_csrf');
    }

    private static function parseNamed(?string $cookie,string $expected):?string
    {
        if ($cookie === null) return null;
        foreach (explode(';', $cookie) as $part) {
            [$name, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($name === $expected && preg_match('/^[A-Za-z0-9_-]{43}$/D', $value)) return $value;
        }
        return null;
    }
}
