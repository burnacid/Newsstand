<?php

namespace App\Services\Reader;

use App\Models\Entry;
use App\Models\User;
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
            'feed' => $query->whereIn('entries.feed_id', $this->subscribedFeedIds($user))->where('entries.feed_id', $source->id),
            'folder' => $query->whereIn('entries.feed_id', $this->subscribedFeedIds($user, $source->id)),
            default => $query->whereIn('entries.feed_id', $this->subscribedFeedIds($user)),
        };

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
            ->join('subscriptions as s', fn (JoinClause $j) => $j
                ->on('s.feed_id', '=', 'entries.feed_id')
                ->where('s.user_id', $user->id))
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
    private function applySearch(Builder $query, string $search): void
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
            ->select('entries.*', 'eu.read_at', 'eu.starred_at', 'eu.read_later_at')
            ->leftJoin('entry_user as eu', fn (JoinClause $j) => $j
                ->on('eu.entry_id', '=', 'entries.id')
                ->where('eu.user_id', $user->id));
    }

    private function subscribedFeedIds(User $user, ?int $folderId = null): QueryBuilder
    {
        return DB::table('subscriptions')
            ->select('feed_id')
            ->where('user_id', $user->id)
            ->when($folderId !== null, fn (QueryBuilder $q) => $q->where('folder_id', $folderId));
    }

    private function taggedEntryIds(User $user, ?int $tagId = null): QueryBuilder
    {
        return DB::table('entry_tag')
            ->select('entry_tag.entry_id')
            ->join('tags', 'tags.id', '=', 'entry_tag.tag_id')
            ->where('tags.user_id', $user->id)
            ->when($tagId !== null, fn (QueryBuilder $q) => $q->where('entry_tag.tag_id', $tagId));
    }
}
