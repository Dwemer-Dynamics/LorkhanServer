<?php

declare(strict_types=1);

namespace LorkhanServer\Security;

use InvalidArgumentException;

final class OutboundUrlPolicy
{
    /** @param list<string> $allowedHosts */
    public static function validate(string $url, array $allowedHosts, bool $allowLoopbackHttp = false, ?array &$resolvedAddresses = null, bool $localNetwork = false): string
    {
        $parts = parse_url($url);
        $scheme = $parts['scheme'] ?? null;
        if (!is_array($parts) || !in_array($scheme, ($allowLoopbackHttp || $localNetwork) ? ['https', 'http'] : ['https'], true)
            || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('unsafe_provider_url');
        }
        $host = strtolower(rtrim((string) $parts['host'], '.'));
        if ($allowedHosts === [] || !in_array($host, array_map(static fn(string $v): string => strtolower(rtrim($v, '.')), $allowedHosts), true)) {
            throw new InvalidArgumentException('provider_host_not_allowed');
        }
        if ($localNetwork) {
            if (!self::isLocalLlmHost($host) || isset($parts['query'])) throw new InvalidArgumentException('invalid_local_llm_endpoint');
            $resolvedAddresses=[$host==='localhost'?'127.0.0.1':trim($host,'[]')];
            return $url;
        }
        if ($scheme === 'http') {
            if (!$allowLoopbackHttp || !self::isLoopback($host)) throw new InvalidArgumentException('provider_host_private');
            $resolvedAddresses = [$host === 'localhost' ? '127.0.0.1' : $host];
            return $url;
        }
        $literalAddress = trim($host, '[]');
        $addresses = filter_var($literalAddress, FILTER_VALIDATE_IP) ? [$literalAddress] : array_values(array_unique(array_merge(
            array_column(dns_get_record($host, DNS_A) ?: [], 'ip'),
            array_column(dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        )));
        if ($addresses === []) throw new InvalidArgumentException('provider_host_unresolved');
        foreach ($addresses as $address) self::rejectAddress((string) $address);
        $resolvedAddresses = $addresses;
        return $url;
    }

    /** Pin checked DNS answers; explicit imported endpoints must not use an unchecked proxy resolution. */
    public static function curlOptions(string $url, array $allowedHosts, bool $allowLoopbackHttp, bool $directConnection, bool $localNetwork = false): array
    {
        $addresses = [];
        self::validate($url, $allowedHosts, $allowLoopbackHttp, $addresses, $localNetwork);
        $parts = parse_url($url);
        $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
        $addresses = array_map(static fn(string $address): string => str_contains($address, ':') ? '[' . $address . ']' : $address, $addresses);
        // Literal IPs do not resolve; older libcurl releases cannot use IPv6 literals as resolve keys.
        $options = filter_var(trim($parts['host'], '[]'), FILTER_VALIDATE_IP) ? []
            : [CURLOPT_RESOLVE => [$parts['host'] . ':' . $port . ':' . implode(',', $addresses)]];
        if ($directConnection || $localNetwork) $options[CURLOPT_PROXY] = '';
        return $options;
    }

    /** Explicit Local LLM connectors accept literal LAN/loopback addresses, never public DNS or metadata IPs. */
    public static function isLocalLlmHost(string $host): bool
    {
        $host=strtolower(trim($host,'[]'));
        if ($host==='localhost') return true;
        $packed=@inet_pton($host);
        if ($packed===false || $packed===inet_pton('fd00:ec2::254')) return false;
        if (strlen($packed)===4) {
            $a=ord($packed[0]);$b=ord($packed[1]);
            return $a===127 || $a===10 || ($a===172&&$b>=16&&$b<=31) || ($a===192&&$b===168);
        }
        return $packed===str_repeat("\0",15)."\1" || (ord($packed[0])&0xfe)===0xfc;
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
        // PHP's private/reserved flags do not reject IPv4-mapped private IPv6 addresses.
        $packed = inet_pton($address);
        if (is_string($packed) && strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $address = (string) inet_ntop(substr($packed, 12));
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new InvalidArgumentException('provider_host_private');
        }
    }
}
