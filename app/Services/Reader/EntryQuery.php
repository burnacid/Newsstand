<?php

namespace App\Services\Reader;

use App\Models\Cve;
use App\Models\Entry;
use App\Models\User;
use App\Services\Teams\FeedAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds entry queries scoped to what a user may see, with their
 * read / starred / read-later state joined in.
 */
class EntryQuery
{
    /** Time filters offered in the reader, in hours. */
    public const PERIODS = ['day' => 24, 'week' => 24 * 7, 'month' => 24 * 30];

    /**
     * Entries for a reader source.
     *
     * @param  list<int>  $keepIds  entries to keep in an unread-only list even after they're read
     * @param  string|null  $period  one of self::PERIODS keys, or null for any time
     * @return Builder<Entry>
     */
    public function for(
        User $user,
        ReaderSource $source,
        bool $unreadOnly = false,
        ?string $search = null,
        array $keepIds = [],
        ?string $period = null,
    ): Builder {
        $query = $this->base($user);

        match ($source->type) {
            'starred' => $query->whereNotNull('eu.starred_at'),
            'later' => $query->whereNotNull('eu.read_later_at'),
            'tag' => $query->whereIn('entries.id', $this->taggedEntryIds($user, $source->id)),
            'watchlist' => $query->whereIn('entries.feed_id', $this->subscribedFeedIds($user))->whereIn('entries.id', $this->watchlistEntryIds($user)),
            'feed' => $query->whereIn('entries.feed_id', $this->subscribedFeedIds($user))->where('entries.feed_id', $source->id),
            'folder' => $query->whereIn('entries.feed_id', $this->subscribedFeedIds($user, $source->id)),
            default => $query->whereIn('entries.feed_id', $this->subscribedFeedIds($user)),
        };

        // Stories muted by a rule stay out of feed listings (still visible in your collections).
        if (! $source->isCollection()) {
            $query->whereNull('eu.muted_at');
        }

        if ($unreadOnly) {
            $query->where(function (Builder $q) use ($source, $keepIds) {
                $q->where(function (Builder $q) use ($source) {
                    $q->whereNull('eu.read_at');

                    if (! $source->isCollection()) {
                        $q->where('entries.published_at', '>=', $this->unreadCutoff());
                    }
                });

                if ($keepIds !== []) {
                    $q->orWhereIn('entries.id', $keepIds);
                }
            });
        }

        if ($period !== null && isset(self::PERIODS[$period])) {
            $query->where('entries.published_at', '>=', Carbon::now()->subHours(self::PERIODS[$period]));
        }

        if ($search !== null && trim($search) !== '') {
            $this->applySearch($query, trim($search));
        }

        return $query
            ->orderByDesc('entries.published_at')
            ->orderByDesc('entries.id');
    }

    /**
     * A single entry the user is allowed to read: from a subscribed feed,
     * or one they saved (starred, read later or tagged).
     */
    public function find(User $user, int $id): ?Entry
    {
        return $this->base($user)
            ->with('feed')
            ->where('entries.id', $id)
            ->where(fn (Builder $q) => $q
                ->whereIn('entries.feed_id', $this->subscribedFeedIds($user))
                ->orWhereNotNull('eu.starred_at')
                ->orWhereNotNull('eu.read_later_at')
                ->orWhereIn('entries.id', $this->taggedEntryIds($user)))
            ->first();
    }

    /**
     * Unread counts per subscribed feed.
     *
     * @return Collection<int, int> feed_id => count
     */
    public function unreadCounts(User $user): Collection
    {
        return Entry::query()
            ->whereIn('entries.feed_id', FeedAccess::feedIds($user))
            ->leftJoin('entry_user as eu', fn (JoinClause $j) => $j
                ->on('eu.entry_id', '=', 'entries.id')
                ->where('eu.user_id', $user->id))
            ->whereNull('eu.read_at')
            ->where('entries.published_at', '>=', $this->unreadCutoff())
            ->groupBy('entries.feed_id')
            ->selectRaw('entries.feed_id, count(*) as unread')
            ->toBase()
            ->pluck('unread', 'feed_id')
            ->map(fn ($count) => (int) $count);
    }

    /** @return array{starred: int, later: int} */
    public function collectionCounts(User $user): array
    {
        $row = DB::table('entry_user')
            ->where('user_id', $user->id)
            ->selectRaw('count(starred_at) as starred, count(read_later_at) as later')
            ->first();

        return ['starred' => (int) ($row->starred ?? 0), 'later' => (int) ($row->later ?? 0)];
    }

    /**
     * Tags for a set of entries, for display.
     *
     * @param  list<int>  $entryIds
     * @return Collection<int|string, Collection<int, \stdClass>> entry_id => rows with id, name, color
     */
    public function tagsFor(User $user, array $entryIds): Collection
    {
        if ($entryIds === []) {
            return collect();
        }

        return DB::table('entry_tag')
            ->join('tags', 'tags.id', '=', 'entry_tag.tag_id')
            ->where('tags.user_id', $user->id)
            ->whereIn('entry_tag.entry_id', $entryIds)
            ->orderBy('tags.name')
            ->get(['entry_tag.entry_id', 'tags.id', 'tags.name', 'tags.color'])
            ->groupBy('entry_id');
    }

    /**
     * Security and coverage signals for display: CVEs (with score / KEV) and
     * how many feeds cover the story.
     *
     * @param  list<int>  $entryIds
     * @return array<int, array{cves: Collection<int, Cve>, sources: int}>
     */
    public function signalsFor(array $entryIds): array
    {
        if ($entryIds === []) {
            return [];
        }

        $cves = Cve::query()
            ->join('cve_entry', 'cve_entry.cve_id', '=', 'cves.id')
            ->whereIn('cve_entry.entry_id', $entryIds)
            ->orderByDesc('cves.in_kev')
            ->orderByRaw('cves.cvss_score is null, cves.cvss_score desc')
            ->get(['cves.*', 'cve_entry.entry_id'])
            ->groupBy('entry_id');

        $sources = DB::table('entries')
            ->join('story_clusters', 'story_clusters.id', '=', 'entries.cluster_id')
            ->whereIn('entries.id', $entryIds)
            ->pluck('story_clusters.size', 'entries.id');

        $signals = [];
        foreach ($entryIds as $id) {
            $signals[$id] = [
                'cves' => $cves->get($id, collect())->values(),
                'sources' => (int) ($sources[$id] ?? 1),
            ];
        }

        return $signals;
    }

    public function unreadCutoff(): Carbon
    {
        return Carbon::now()->subDays(config('newsstand.unread_max_age_days'));
    }

    public static function usesFullTextIndex(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * PostgreSQL: the weighted tsvector index, with web-search syntax
     * ("exact phrase", -exclude, OR). Elsewhere: case-insensitive substring match.
     *
     * @param  Builder<Entry>  $query
     */
    public function applySearch(Builder $query, string $search): void
    {
        if (self::usesFullTextIndex()) {
            $query->whereRaw("entries.search_vector @@ websearch_to_tsquery('simple', ?)", [$search]);

            return;
        }

        $term = '%'.$search.'%';
        $query->where(fn (Builder $q) => $q
            ->whereLike('entries.title', $term)
            ->orWhereLike('entries.summary', $term)
            ->orWhereLike('entries.author', $term)
            ->orWhereLike('entries.content', $term));
    }

    /** @return Builder<Entry> */
    private function base(User $user): Builder
    {
        return Entry::query()
            ->select('entries.*', 'eu.read_at', 'eu.starred_at', 'eu.read_later_at', 'eu.highlight', 'eu.muted_at')
            ->leftJoin('entry_user as eu', fn (JoinClause $j) => $j
                ->on('eu.entry_id', '=', 'entries.id')
                ->where('eu.user_id', $user->id));
    }

    public function subscribedFeedIds(User $user, ?int $folderId = null): QueryBuilder
    {
        return FeedAccess::feedIds($user, $folderId);
    }

    public function watchlistEntryIds(User $user): QueryBuilder
    {
        return DB::table('watchlist_hits')
            ->select('watchlist_hits.entry_id')
            ->join('watchlist_items', 'watchlist_items.id', '=', 'watchlist_hits.watchlist_item_id')
            ->where(fn (QueryBuilder $q) => $q
                ->where('watchlist_items.user_id', $user->id)
                ->orWhereIn('watchlist_items.team_id', FeedAccess::teamIds($user)));
    }

    public function taggedEntryIds(User $user, ?int $tagId = null): QueryBuilder
    {
        return DB::table('entry_tag')
            ->select('entry_tag.entry_id')
            ->join('tags', 'tags.id', '=', 'entry_tag.tag_id')
            ->where('tags.user_id', $user->id)
            ->when($tagId !== null, fn (QueryBuilder $q) => $q->where('entry_tag.tag_id', $tagId));
    }
}
