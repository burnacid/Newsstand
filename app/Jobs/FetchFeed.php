<?php

namespace App\Jobs;

use App\Models\Feed;
use App\Services\Feeds\FeedRefresher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FetchFeed implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Feed $feed) {}

    public function uniqueId(): string
    {
        return (string) $this->feed->id;
    }

    public function handle(FeedRefresher $refresher): void
    {
        $refresher->refresh($this->feed);
    }
}
