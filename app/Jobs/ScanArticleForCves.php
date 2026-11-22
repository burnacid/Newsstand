<?php

namespace App\Jobs;

use App\Models\Entry;
use App\Services\Clustering\StoryClusterer;
use App\Services\Security\ArticleScanner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Throwable;

/** Fetch a teaser story's article once and link the CVEs it mentions. */
class ScanArticleForCves implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Entry $entry) {}

    /** @return list<object> */
    public function middleware(): array
    {
        // Be polite: limited per site (see AppServiceProvider: 'article-scan').
        return [new RateLimited('article-scan')];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(6);
    }

    public function handle(ArticleScanner $scanner, StoryClusterer $clusterer): void
    {
        try {
            $new = $scanner->scan($this->entry);
        } catch (Throwable $e) {
            // Unreachable or blocked articles just keep what the feed gave us.
            report($e);

            return;
        }

        if ($new === []) {
            return;
        }

        // New CVEs can group the story with others and make rules match.
        $cluster = $clusterer->cluster($this->entry->fresh());
        EvaluateRules::dispatch($cluster ? $cluster->entries()->pluck('id')->all() : [$this->entry->id]);
    }
}
