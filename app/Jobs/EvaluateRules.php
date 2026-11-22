<?php

namespace App\Jobs;

use App\Services\Rules\RuleEvaluator;
use App\Services\Watchlist\WatchlistMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EvaluateRules implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 300;

    /** @var list<int> */
    public array $entryIds;

    /** @param array<array-key, mixed> $entryIds ids from queries; normalized to ints */
    public function __construct(array $entryIds)
    {
        $this->entryIds = array_values(array_map(intval(...), $entryIds));
    }

    /** Watchlist first, so rules with a "on my watchlist" condition see fresh hits. */
    public function handle(RuleEvaluator $evaluator, WatchlistMatcher $watchlist): void
    {
        foreach (array_chunk($this->entryIds, 200) as $chunk) {
            $watchlist->matchEntries($chunk);
            $evaluator->evaluateEntries($chunk);
        }
    }
}
