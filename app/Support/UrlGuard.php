<?php

namespace App\Support;

use App\Exceptions\UnsafeUrlException;
use Psr\Http\Message\RequestInterface;

/**
 * Guards server-side fetches of user-supplied URLs against SSRF:
 * only http(s), and (by default) no hosts resolving to private/reserved IPs.
 */
class UrlGuard
{
    /**
     * Ranges that never belong to a public web server. PHP's FILTER_FLAG_NO_PRIV_RANGE /
     * NO_RES_RANGE miss several of them (CGNAT, benchmarking, NAT64, ...), so they are
     * listed explicitly.
     */
    private const BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12',
        '192.0.0.0/24', '192.0.2.0/24', '192.88.99.0/24', '192.168.0.0/16', '198.18.0.0/15',
        '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
        '::/128', '::1/128', '64:ff9b::/96', '64:ff9b:1::/48', '100::/64', '2001::/32', '2001:db8::/32',
        '2002::/16', 'fc00::/7', 'fe80::/10', 'fec0::/10', 'ff00::/8',
    ];

    /**
     * @param  bool|null  $blockPrivate  override the feed setting (alerts have their own)
     * @return list<string> the checked addresses the host resolves to (empty when the check is off);
     *                      connect to one of these so a second DNS answer can't point elsewhere
     */
    public static function assertSafe(string $url, ?bool $blockPrivate = null): array
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || empty($parts['host'])) {
            throw new UnsafeUrlException("Only http(s) URLs are allowed: {$url}");
        }

        if (! ($blockPrivate ?? config('newsstand.fetch.block_private_hosts'))) {
            return [];
        }

        $host = trim($parts['host'], '[]');

        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : self::resolve($host);

        if ($ips === []) {
            throw new UnsafeUrlException("Could not resolve host: {$host}");
        }

        foreach ($ips as $ip) {
            if (! self::isPublic($ip)) {
                throw new UnsafeUrlException("Host {$host} resolves to a private or reserved address.");
            }
        }

        return $ips;
    }

    /**
     * Guzzle middleware: checks every request (including each redirect hop) and makes
     * curl connect to the address that was checked, so DNS can't answer with a public
     * address for the check and a private one for the connection (DNS rebinding).
     *
     * @param  bool|null  $blockPrivate  as for assertSafe()
     */
    public static function middleware(?bool $blockPrivate = null): callable
    {
        return fn (callable $handler) => function (RequestInterface $request, array $options) use ($handler, $blockPrivate) {
            $uri = $request->getUri();
            $ips = self::assertSafe((string) $uri, $blockPrivate);

            if ($ips !== [] && ! filter_var($uri->getHost(), FILTER_VALIDATE_IP) && defined('CURLOPT_RESOLVE')) {
                $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
                $ip = str_contains($ips[0], ':') ? "[{$ips[0]}]" : $ips[0];
                $options['curl'][CURLOPT_RESOLVE] = ["{$uri->getHost()}:{$port}:{$ip}"];
            }

            return $handler($request, $options);
        };
    }

    public static function isPublic(string $ip): bool
    {
        $packed = @inet_pton($ip);

        if ($packed === false) {
            return false;
        }

        // IPv4-mapped (::ffff:a.b.c.d) and IPv4-compatible (::a.b.c.d) addresses reach IPv4 hosts.
        if (strlen($packed) === 16 && (str_starts_with($packed, str_repeat("\0", 10)."\xff\xff") || str_starts_with($packed, str_repeat("\0", 12)))) {
            $v4 = substr($packed, 12);

            if ($v4 !== "\0\0\0\0" && $v4 !== "\0\0\0\1") {
                $packed = $v4;
            }
        }

        foreach (self::BLOCKED as $cidr) {
            if (self::inRange($packed, $cidr)) {
                return false;
            }
        }

        return (bool) filter_var(inet_ntop($packed), FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private static function inRange(string $packed, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $net = (string) inet_pton($subnet);

        if (strlen($net) !== strlen($packed)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);

        if (strncmp($packed, $net, $bytes) !== 0) {
            return false;
        }

        if ($bits % 8 === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $bits % 8) & 0xFF;

        return (ord($packed[$bytes]) & $mask) === (ord($net[$bytes]) & $mask);
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
