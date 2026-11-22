<?php

namespace App\Jobs;

use App\Services\Rules\RuleEvaluator;
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

    public function handle(RuleEvaluator $evaluator): void
    {
        foreach (array_chunk($this->entryIds, 200) as $chunk) {
            $evaluator->evaluateEntries($chunk);
        }
    }
}
