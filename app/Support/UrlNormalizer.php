<?php

namespace App\Support;

class UrlNormalizer
{
    /** Query parameters that only carry tracking info and never change the resource. */
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'fbclid', 'gclid', 'mc_cid', 'mc_eid', 'ref', 'ref_src', '_hsenc', '_hsmi',
    ];

    /**
     * Normalize a URL so trivially different spellings map to the same value:
     * lowercase scheme/host, drop default ports, fragments and tracking params.
     */
    public static function normalize(string $url): string
    {
        $url = trim($url);

        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://'.ltrim($url, '/');
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            return $url;
        }

        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? null;

        if (($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443)) {
            $port = null;
        }

        $path = $parts['path'] ?? '/';
        $path = $path === '' ? '/' : $path;

        $query = '';
        if (! empty($parts['query'])) {
            parse_str($parts['query'], $params);
            $params = array_diff_key($params, array_flip(self::TRACKING_PARAMS));
            ksort($params);
            $query = $params ? '?'.http_build_query($params) : '';
        }

        $auth = isset($parts['user']) ? $parts['user'].(isset($parts['pass']) ? ':'.$parts['pass'] : '').'@' : '';

        return $scheme.'://'.$auth.$host.($port ? ':'.$port : '').$path.$query;
    }

    public static function hash(string $url): string
    {
        return sha1(self::normalize($url));
    }

    /** Resolve a possibly-relative URL against a base URL. */
    public static function resolve(string $url, string $base): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            return $url;
        }

        $b = parse_url($base);
        $origin = ($b['scheme'] ?? 'https').'://'.($b['host'] ?? '').(isset($b['port']) ? ':'.$b['port'] : '');

        if (str_starts_with($url, '//')) {
            return ($b['scheme'] ?? 'https').':'.$url;
        }

        if (str_starts_with($url, '/')) {
            return $origin.$url;
        }

        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');

        return $origin.$dir.$url;
    }
}
