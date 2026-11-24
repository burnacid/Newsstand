<?php

namespace App\Services\Feeds;

use App\Exceptions\ResponseTooLargeException;
use App\Support\UrlGuard;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;

/**
 * HTTP client for fetching user-supplied URLs: custom UA, timeouts,
 * a response size cap, and SSRF checks on the URL and every redirect.
 */
class FeedHttp
{
    public function client(): PendingRequest
    {
        $maxBytes = (int) config('newsstand.fetch.max_bytes');

        return Http::withUserAgent(config('newsstand.fetch.user_agent'))
            ->timeout(config('newsstand.fetch.timeout'))
            ->connectTimeout(10)
            ->accept('application/rss+xml, application/atom+xml, application/xml;q=0.9, text/xml;q=0.9, text/html;q=0.8, */*;q=0.5')
            // Runs for the first request and again for every redirect hop.
            ->withMiddleware(UrlGuard::middleware())
            ->withOptions([
                'allow_redirects' => [
                    'max' => config('newsstand.fetch.max_redirects'),
                    'protocols' => ['http', 'https'],
                    'track_redirects' => true,
                ],
                'on_headers' => function (ResponseInterface $response) use ($maxBytes) {
                    if ((int) $response->getHeaderLine('Content-Length') > $maxBytes) {
                        throw new ResponseTooLargeException;
                    }
                },
                // Chunked responses have no Content-Length: stop once too much has arrived.
                'progress' => function (int $total, int $downloaded) use ($maxBytes) {
                    if ($downloaded > $maxBytes) {
                        throw new ResponseTooLargeException;
                    }
                },
            ]);
    }

    /** @param array<string, string> $headers */
    public function get(string $url, array $headers = []): Response
    {
        try {
            // The client's middleware checks this URL and every redirect target.
            return $this->client()->withHeaders($headers)->get($url);
        } catch (ConnectionException $e) {
            // Guzzle wraps an aborted download; report the reason instead.
            for ($cause = $e->getPrevious(); $cause; $cause = $cause->getPrevious()) {
                if ($cause instanceof ResponseTooLargeException) {
                    throw $cause;
                }
            }

            throw $e;
        }
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
