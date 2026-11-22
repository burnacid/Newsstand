<?php

namespace App\Services\Feeds;

use App\Exceptions\InvalidFeedException;
use App\Services\Feeds\Data\FeedCandidate;
use App\Support\UrlNormalizer;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use Throwable;

/**
 * Given a feed URL or a website URL, find the feed(s) to subscribe to.
 */
class FeedDiscovery
{
    private const FEED_TYPES = [
        'application/rss+xml',
        'application/atom+xml',
        'application/rdf+xml',
        'application/xml',
        'text/xml',
    ];

    private const COMMON_PATHS = ['/feed', '/rss', '/feed.xml', '/rss.xml', '/atom.xml', '/index.xml'];

    public function __construct(
        private FeedHttp $http,
        private FeedParser $parser,
    ) {}

    /** @return list<FeedCandidate> */
    public function discover(string $input): array
    {
        $url = UrlNormalizer::normalize($input);
        $response = $this->http->get($url);

        if (! $response->successful()) {
            throw new RuntimeException(self::explainStatus($response->status()));
        }

        $effective = FeedHttp::effectiveUrl($response, $url);

        // 1. The URL itself is a feed.
        try {
            $parsed = $this->parser->parse($response->body(), $effective);

            return [new FeedCandidate($effective, $parsed->title)];
        } catch (InvalidFeedException) {
            // Not a feed; treat it as an HTML page.
        }

        // 2. <link rel="alternate"> tags in the page.
        $candidates = $this->alternateLinks($response->body(), $effective);

        if ($candidates !== []) {
            return $candidates;
        }

        // 3. Well-known feed locations on the same origin.
        return $this->probeCommonPaths($effective);
    }

    /** A human explanation for an HTTP error from a feed or site. */
    public static function explainStatus(int $status): string
    {
        return match (true) {
            $status === 401, $status === 403 => "The site refused the request (HTTP {$status}). Some sites block automated feed readers.",
            $status === 404, $status === 410 => "Nothing was found at that address (HTTP {$status}).",
            $status === 429 => 'The site is rate limiting requests (HTTP 429). Try again later.',
            $status >= 500 => "The site is having problems (HTTP {$status}). Try again later.",
            default => "The site responded with HTTP {$status}.",
        };
    }

    /** @return list<FeedCandidate> */
    public function alternateLinks(string $html, string $baseUrl): array
    {
        if (trim($html) === '') {
            return [];
        }

        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $candidates = [];
        foreach ((new DOMXPath($dom))->query('//link[@href]') ?: [] as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            $rel = strtolower($link->getAttribute('rel'));
            $type = strtolower(trim(explode(';', $link->getAttribute('type'))[0]));

            if (! str_contains(" {$rel} ", ' alternate ') || ! in_array($type, self::FEED_TYPES, true)) {
                continue;
            }

            $href = UrlNormalizer::resolve(trim($link->getAttribute('href')), $baseUrl);
            $title = trim($link->getAttribute('title')) ?: null;

            $candidates[UrlNormalizer::hash($href)] ??= new FeedCandidate($href, $title);
        }

        return array_values($candidates);
    }

    /** @return list<FeedCandidate> */
    private function probeCommonPaths(string $baseUrl): array
    {
        foreach (self::COMMON_PATHS as $path) {
            $candidate = UrlNormalizer::resolve($path, $baseUrl);

            try {
                $response = $this->http->get($candidate);

                if ($response->successful()) {
                    $parsed = $this->parser->parse($response->body(), $candidate);

                    return [new FeedCandidate(FeedHttp::effectiveUrl($response, $candidate), $parsed->title)];
                }
            } catch (Throwable) {
                continue;
            }
        }

        return [];
    }
}
