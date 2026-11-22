<?php

namespace App\Services\Watchlist;

use App\Models\CveProduct;
use App\Models\Entry;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Services\Reader\EntryQuery;
use App\Services\Reader\ReaderSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Decides which stories concern something on a user's watchlist:
 *   cve   a CVE in the story affects a watched product (NVD CPE data or CISA KEV)
 *   text  the story names the product (or an alias) as a whole word
 * Hits are stored once in watchlist_hits.
 */
class WatchlistMatcher
{
    public function __construct(private EntryQuery $entries) {}

    /**
     * Match stories against the watchlists of everyone subscribed to their feeds.
     *
     * @param  list<int>  $entryIds
     * @return int new hits
     */
    public function matchEntries(array $entryIds): int
    {
        if ($entryIds === []) {
            return 0;
        }

        $entries = Entry::with('cves:id')->whereKey($entryIds)->get();
        $new = 0;

        foreach ($entries->groupBy('feed_id') as $feedId => $feedEntries) {
            $items = WatchlistItem::query()
                ->whereIn('user_id', DB::table('subscriptions')->select('user_id')->where('feed_id', $feedId))
                ->get();

            foreach ($items as $item) {
                $new += $this->record($item, $feedEntries);
            }
        }

        return $new;
    }

    /**
     * Backfill one (new or edited) item over the owner's recent stories.
     *
     * @return int new hits
     */
    public function matchItem(WatchlistItem $item, int $days = 30): int
    {
        $new = 0;

        $this->entries->for($item->user, ReaderSource::all())
            ->where('entries.published_at', '>=', now()->subDays($days))
            ->with('cves:id')
            ->reorder()
            ->orderBy('entries.id')
            ->chunk(300, function (Collection $entries) use ($item, &$new) {
                $new += $this->record($item, $entries);
            });

        return $new;
    }

    /**
     * CVE ids affecting any of the user's watched products (whether or not a story mentions them).
     *
     * @return Collection<int, int>
     */
    public function cveIdsFor(User $user): Collection
    {
        $items = $user->watchlist()->get();

        if ($items->isEmpty()) {
            return collect();
        }

        return CveProduct::query()
            ->where(function ($q) use ($items) {
                foreach ($items as $item) {
                    $q->orWhere(fn ($q) => $item->constrainProducts($q));
                }
            })
            ->distinct()
            ->pluck('cve_id');
    }

    /**
     * Watchlist labels per entry, for the "affects your stack" badge.
     *
     * @param  list<int>  $entryIds
     * @return Collection<int|string, Collection<int, string>> entry_id => labels
     */
    public function labelsFor(User $user, array $entryIds): Collection
    {
        if ($entryIds === []) {
            return collect();
        }

        return DB::table('watchlist_hits')
            ->join('watchlist_items', 'watchlist_items.id', '=', 'watchlist_hits.watchlist_item_id')
            ->where('watchlist_items.user_id', $user->id)
            ->whereIn('watchlist_hits.entry_id', $entryIds)
            ->get(['watchlist_hits.entry_id', 'watchlist_items.vendor', 'watchlist_items.product'])
            ->groupBy('entry_id')
            ->map(fn (Collection $rows) => $rows->map(fn ($r) => trim($r->vendor.' '.($r->product ?? '')))->unique()->values());
    }

    /** @param Collection<int, Entry> $entries */
    private function record(WatchlistItem $item, Collection $entries): int
    {
        $cveIds = $entries->flatMap(fn (Entry $e) => $e->cves->pluck('id'))->unique()->values();

        // Which of these CVEs affect the watched product?
        $affected = $cveIds->isEmpty()
            ? collect()
            : $item->cveProducts()->whereIn('cve_id', $cveIds)->distinct()->pluck('cve_id')->flip();

        $pattern = $this->pattern($item);
        $rows = [];

        foreach ($entries as $entry) {
            $cve = $entry->cves->first(fn ($c) => $affected->has($c->id));

            if ($cve) {
                $rows[] = ['watchlist_item_id' => $item->id, 'entry_id' => $entry->id, 'cve_id' => $cve->id, 'reason' => 'cve', 'created_at' => now()];
            } elseif ($pattern && preg_match($pattern, $this->text($entry)) === 1) {
                $rows[] = ['watchlist_item_id' => $item->id, 'entry_id' => $entry->id, 'cve_id' => null, 'reason' => 'text', 'created_at' => now()];
            }
        }

        return $rows === [] ? 0 : DB::table('watchlist_hits')->insertOrIgnore($rows);
    }

    /** Whole-word, case-insensitive match on any of the item's terms. */
    private function pattern(WatchlistItem $item): ?string
    {
        $terms = $item->terms();

        if ($terms === []) {
            return null;
        }

        $alternatives = implode('|', array_map(fn (string $t) => preg_quote($t, '/'), $terms));

        return '/(?<![\p{L}\p{N}])(?:'.$alternatives.')(?![\p{L}\p{N}])/iu';
    }

    private function text(Entry $entry): string
    {
        return implode("\n", [$entry->title, $entry->summary, strip_tags((string) $entry->content)]);
    }
}
