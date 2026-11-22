<?php

namespace App\Jobs;

use App\Models\Entry;
use App\Services\Feeds\FullTextExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExtractFullText implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Entry $entry) {}

    public function handle(FullTextExtractor $extractor): void
    {
        $extractor->extract($this->entry);
    }
}
