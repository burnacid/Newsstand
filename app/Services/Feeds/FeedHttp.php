<?php

namespace App\Services\Feeds;

use App\Support\UrlGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use RuntimeException;

/**
 * HTTP client for fetching user-supplied URLs: custom UA, timeouts,
 * a response size cap, and SSRF checks on the URL and every redirect.
 */
class FeedHttp
{
    public function client(): PendingRequest
    {
        $maxBytes = config('newsstand.fetch.max_bytes');

        return Http::withUserAgent(config('newsstand.fetch.user_agent'))
            ->timeout(config('newsstand.fetch.timeout'))
            ->connectTimeout(10)
            ->accept('application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.9, text/html;q=0.8, */*;q=0.5')
            ->withOptions([
                'allow_redirects' => [
                    'max' => config('newsstand.fetch.max_redirects'),
                    'protocols' => ['http', 'https'],
                    'track_redirects' => true,
                    'on_redirect' => function (RequestInterface $request, ResponseInterface $response, UriInterface $uri) {
                        UrlGuard::assertSafe((string) $uri);
                    },
                ],
                'on_headers' => function (ResponseInterface $response) use ($maxBytes) {
                    if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                        throw new RuntimeException('Response is larger than the allowed maximum.');
                    }
                },
            ]);
    }

    /** @param array<string, string> $headers */
    public function get(string $url, array $headers = []): Response
    {
        UrlGuard::assertSafe($url);

        return $this->client()->withHeaders($headers)->get($url);
    }

    /** The final URL after redirects. */
    public static function effectiveUrl(Response $response, string $requested): string
    {
        $history = $response->header('X-Guzzle-Redirect-History');

        if ($history === '') {
            return $requested;
        }

        $urls = array_map('trim', explode(',', $history));

        return end($urls) ?: $requested;
    }
}
