<?php

namespace App\Services\Feeds;

use App\Models\Feed;
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

        try {
            $response = $this->http->get($feed->url, array_filter([
                'If-None-Match' => $feed->etag,
                'If-Modified-Since' => $feed->last_modified,
            ]));

            if ($response->status() !== 304) {
                if (! $response->successful()) {
                    throw new RuntimeException("HTTP {$response->status()} {$response->reason()}");
                }

                $parsed = $this->parser->parse($response->body(), $feed->url);

                $feed->title ??= $parsed->title ? Str::limit($parsed->title, 250) : null;
                $feed->site_url = $parsed->siteUrl ?? $feed->site_url;
                $feed->description = $parsed->description ?? $feed->description;
                $feed->etag = $response->header('ETag') ?: null;
                $feed->last_modified = $response->header('Last-Modified') ?: null;

                $new = $this->processor->store($feed, $parsed->items)->count();
            }

            $feed->error_count = 0;
            $feed->last_error = null;
            $feed->last_success_at = now();
            $feed->fetch_interval = $this->interval($feed);
            $feed->next_fetch_at = now()->addMinutes($feed->fetch_interval);
        } catch (Throwable $e) {
            $feed->error_count++;
            $feed->last_error = Str::limit($e->getMessage(), 1000);
            $feed->next_fetch_at = now()->addMinutes($this->backoff($feed));

            report($e);
        } finally {
            $feed->save();
        }

        return $new;
    }

    /**
     * Poll roughly twice per average gap between the most recent posts.
     * Based on the actual spacing (not a fixed window), because busy feeds
     * often only carry their last ~15 items.
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

        if ($dates->count() < 2) {
            return $max;
        }

        $averageGap = $dates->last()->diffInMinutes($dates->first(), true) / ($dates->count() - 1);

        return (int) max($min, min($max, round($averageGap / 2)));
    }

    private function backoff(Feed $feed): int
    {
        $base = max($feed->fetch_interval, config('newsstand.fetch.min_interval'));

        return (int) min($base * (2 ** min($feed->error_count - 1, 10)), config('newsstand.fetch.max_backoff'));
    }
}
