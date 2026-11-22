<?php

namespace App\Jobs;

use App\Models\Entry;
use App\Services\Feeds\FullTextExtractor;
use App\Services\Security\CveExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractFullText implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Entry $entry) {}

    public function handle(FullTextExtractor $extractor, CveExtractor $cves): void
    {
        // The full article may mention CVEs the feed summary didn't.
        if ($extractor->extract($this->entry) && $cves->extract($this->entry) !== []) {
            EvaluateRules::dispatch([$this->entry->id]);
        }
    }
}
