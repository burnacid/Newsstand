<?php

namespace App\Jobs;

use App\Models\Entry;
use App\Services\Clustering\StoryClusterer;
use App\Services\Security\ArticleScanner;
use App\Services\Security\CveExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * After new stories arrive: find CVEs, group with the same story from other
 * feeds, then run users' rules. Rules on other stories in a cluster that grew
 * are re-run too ("covered by N sources" may now match).
 */
class ProcessEntrySignals implements ShouldQueue
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

    public function handle(CveExtractor $cves, StoryClusterer $clusterer): void
    {
        // Skip stories already done: the hourly signals:process sweep can queue
        // a story again while its first job is still waiting.
        $entries = Entry::with('feed')->whereKey($this->entryIds)->where('signals_processed', false)->orderBy('published_at')->get();

        if ($entries->isEmpty()) {
            return;
        }

        $toEvaluate = $entries->pluck('id')->all();

        foreach ($entries as $entry) {
            $cves->extract($entry);

            if ($cluster = $clusterer->cluster($entry)) {
                // Siblings' source count changed.
                $toEvaluate = [...$toEvaluate, ...$cluster->entries()->pluck('id')->all()];
            }

            $entry->forceFill(['signals_processed' => true])->save();

            // Teaser-only feeds: look in the article itself for CVEs.
            if (ArticleScanner::shouldScan($entry)) {
                ScanArticleForCves::dispatch($entry);
            }
        }

        EvaluateRules::dispatch(array_values(array_unique($toEvaluate)));
    }
}
