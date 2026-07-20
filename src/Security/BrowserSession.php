<?php

declare(strict_types=1);

namespace ALMSIVIserver\Security;

final class BrowserSession
{
    public static function token(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
    public static function hash(string $value): string { return hash('sha256', $value); }

    public static function cookie(string $token, int $maxAge,string $path='/ALMSIVIserver/manage'): string
    {
        return 'almsivi_management=' . rawurlencode($token) . '; Path='.$path.'; Max-Age=' . $maxAge
            . '; HttpOnly; SameSite=Strict';
    }

    public static function csrfCookie(string $token, int $maxAge,string $path='/ALMSIVIserver/manage'): string
    {
        return 'almsivi_csrf=' . rawurlencode($token) . '; Path='.$path.'; Max-Age=' . $maxAge . '; SameSite=Strict';
    }

    public static function clearCookies(): string
    {
        return 'almsivi_management=; Path=/ALMSIVIserver/manage; Max-Age=0; HttpOnly; SameSite=Strict, '
            . 'almsivi_csrf=; Path=/ALMSIVIserver/manage; Max-Age=0; SameSite=Strict';
    }

    public static function parse(?string $cookie): ?string
    {
        return self::parseNamed($cookie, 'almsivi_management');
    }

    public static function parseCsrf(?string $cookie): ?string
    {
        return self::parseNamed($cookie, 'almsivi_csrf');
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
