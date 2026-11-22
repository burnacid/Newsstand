<?php

namespace App\Support;

use App\Exceptions\UnsafeUrlException;

/**
 * Guards server-side fetches of user-supplied URLs against SSRF:
 * only http(s), and (by default) no hosts resolving to private/reserved IPs.
 */
class UrlGuard
{
    public static function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new UnsafeUrlException("Only http(s) URLs are allowed: {$url}");
        }

        if (! config('newsstand.fetch.block_private_hosts')) {
            return;
        }

        $host = trim($parts['host'], '[]');

        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : self::resolve($host);

        if ($ips === []) {
            throw new UnsafeUrlException("Could not resolve host: {$host}");
        }

        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                throw new UnsafeUrlException("Host {$host} resolves to a private or reserved address.");
            }
        }
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $ips = gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $ips[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($ips));
    }
}
