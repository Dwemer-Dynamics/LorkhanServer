<?php

declare(strict_types=1);

namespace ALMSIVIserver\Security;

use InvalidArgumentException;

final class OutboundUrlPolicy
{
    /** @param list<string> $allowedHosts */
    public static function validate(string $url, array $allowedHosts, bool $allowLoopbackHttp = false): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;
        if (!is_array($parts) || !in_array($scheme, $allowLoopbackHttp ? ['https', 'http'] : ['https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('unsafe_provider_url');
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($allowedHosts === [] || !in_array($host, array_map(static fn(string $v): string => strtolower(rtrim($v, '.')), $allowedHosts), true)) {
            throw new InvalidArgumentException('provider_host_not_allowed');
        }
        if ($scheme === 'http') {
            if (!$allowLoopbackHttp || !self::isLoopback($host)) throw new InvalidArgumentException('provider_host_private');
            return $url;
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) self::rejectAddress($host);
        $addresses = array_values(array_unique(array_merge(
            array_column(dns_get_record($host, DNS_A) ?: [], 'ip'),
            array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        )));
        if ($addresses === []) throw new InvalidArgumentException('provider_host_unresolved');
        foreach ($addresses as $address) self::rejectAddress((string) $address);
        return $url;
    }

    private static function isLoopback(string $host): bool
    {
        if ($host === 'localhost' || $host === '::1') return true;
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            && str_starts_with($host, '127.');
    }

    private static function rejectAddress(string $address): void
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new InvalidArgumentException('provider_host_private');
        }
    }
}
