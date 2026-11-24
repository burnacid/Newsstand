<?php

namespace App\Services\Feeds;

use App\Models\Feed;
use App\Support\UrlNormalizer;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Fetches one feed: conditional GET, parse, store entries, then reschedule
 * with an adaptive interval (or exponential backoff after errors).
 */
class FeedRefresher
{
    public function __construct(
        private FeedHttp $http,
        private FeedParser $parser,
        private EntryProcessor $processor,
    ) {}

    /** @return int number of new entries */
    public function refresh(Feed $feed): int
    {
        $feed->last_fetched_at = now();
        $new = 0;
        $waitAtLeast = 0; // minutes the site asked us to stay away

        try {
            $response = $this->http->get($feed->url, array_filter([
                'If-None-Match' => $feed->etag,
                'If-Modified-Since' => $feed->last_modified,
            ]));

            if ($response->status() !== 304) {
                if (! $response->successful()) {
                    $waitAtLeast = match ($response->status()) {
                        // Gone for good: check once a day in case it comes back.
                        410 => (int) config('newsstand.fetch.max_backoff'),
                        429, 503 => $this->retryAfter($response->header('Retry-After')),
                        default => 0,
                    };

                    throw new RuntimeException(FeedDiscovery::explainStatus($response->status()));
                }

                $parsed = $this->parser->parse($response->body(), $feed->url);

                $feed->title ??= $parsed->title ? Str::limit($parsed->title, 250) : null;
                $feed->site_url = $parsed->siteUrl ?? $feed->site_url;
                $feed->description = $parsed->description ?? $feed->description;
                $feed->etag = $response->header('ETag') ?: null;
                $feed->last_modified = $response->header('Last-Modified') ?: null;

                // Also catches subscriptions removed by a cascade (no model events there).
                $feed->full_text = $feed->subscriptions()->where('full_text', true)->exists();

                $new = $this->processor->store($feed, $parsed->items)->count();
                $this->followPermanentRedirect($feed, $response);
            }

            $feed->error_count = 0;
            $feed->last_error = null;
            $feed->last_success_at = now();
            $feed->fetch_interval = $this->interval($feed);
            $feed->next_fetch_at = now()->addMinutes($feed->fetch_interval);
        } catch (Throwable $e) {
            $feed->error_count++;
            $feed->last_error = Str::limit($e->getMessage(), 1000);
            $feed->next_fetch_at = now()->addMinutes(max($this->backoff($feed), $waitAtLeast));

            report($e);
        } finally {
            $feed->save();
        }

        return $new;
    }

    /**
     * Poll roughly twice per typical gap between the most recent posts.
     * Based on the actual spacing (not a fixed window), because busy feeds
     * often only carry their last ~15 items.
     *
     * "Typical" is the median, not the mean: a feed that only posts during
     * office hours has a few long night/weekend gaps that would otherwise
     * drag its daytime polling down (badly so on Monday mornings).
     * Posts less than a minute apart count as one (feeds often publish in
     * batches with one timestamp), or those zero gaps would win the median.
     */
    private function interval(Feed $feed): int
    {
        $min = config('newsstand.fetch.min_interval');
        $max = config('newsstand.fetch.max_interval');

        $dates = $feed->entries()
            ->where('published_at', '>=', now()->subWeeks(2))
            ->latest('published_at')
            ->limit(20)
            ->pluck('published_at');

        $gaps = $dates->sliding(2)
            ->map(fn ($pair) => $pair->last()->diffInMinutes($pair->first(), true))
            ->filter(fn (float $gap) => $gap >= 1);

        if ($gaps->isEmpty()) {
            return $max;
        }

        return (int) max($min, min($max, round($gaps->median() / 2)));
    }

    /**
     * A feed that moved permanently (only 301/308 hops) is stored under its new
     * address, unless another feed already uses that address.
     */
    private function followPermanentRedirect(Feed $feed, Response $response): void
    {
        $statuses = array_filter(array_map('trim', explode(',', $response->header('X-Guzzle-Redirect-Status-History'))));

        if ($statuses === [] || array_diff($statuses, ['301', '308']) !== []) {
            return;
        }

        $target = FeedHttp::effectiveUrl($response, $feed->url);

        if (UrlNormalizer::hash($target) === $feed->url_hash || Feed::findByUrl($target)) {
            return;
        }

        $feed->url = UrlNormalizer::normalize($target);
    }

    /** Minutes from a Retry-After header (seconds or an HTTP date), capped at the maximum backoff. */
    private function retryAfter(string $header): int
    {
        $header = trim($header);

        if ($header === '') {
            return 0;
        }

        $seconds = ctype_digit($header)
            ? (int) $header
            : (int) max(0, (strtotime($header) ?: time()) - time());

        return (int) min((int) ceil($seconds / 60), config('newsstand.fetch.max_backoff'));
    }

    private function backoff(Feed $feed): int
    {
        $base = max($feed->fetch_interval, config('newsstand.fetch.min_interval'));

        return (int) min($base * (2 ** min($feed->error_count - 1, 10)), config('newsstand.fetch.max_backoff'));
    }
}
