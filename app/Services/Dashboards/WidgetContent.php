<?php

namespace App\Services\Dashboards;

use App\Models\Cve;
use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Entry;
use App\Models\User;
use App\Services\Reader\EntryQuery;
use App\Services\Reader\TopStories;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves what a widget shows. Content is scoped to whoever the dashboard belongs to,
 * so members see it without subscribing:
 *   - personal dashboard: the OWNER's feeds, folders, tags, stars and watchlist;
 *   - team dashboard: the TEAM's feeds, folders and watchlist (no tags or stars).
 * Read state comes from the VIEWER (null on public links).
 */
class WidgetContent
{
    public function __construct(
        private EntryQuery $query,
        private TopStories $top,
    ) {}

    /**
     * @param  bool  $unreadOnly  hide stories the viewer has read (ignored without a viewer)
     * @param  list<int>  $keep  stories to show anyway (opened during this visit)
     * @return Collection<int, Entry>|Collection<int, Cve>
     */
    public function for(DashboardWidget $widget, ?User $viewer, bool $unreadOnly = false, array $keep = []): Collection
    {
        $dashboard = $widget->dashboard;
        $unreadOnly = $unreadOnly && $viewer !== null;

        if ($widget->type === 'cves') {
            return $this->cves($dashboard, (string) ($widget->config['filter'] ?? 'kev'), $widget->limit());
        }

        if ($widget->type === 'top') {
            // Rank first, then drop what the viewer read, so the order stays meaningful.
            $limit = $unreadOnly ? 50 : $widget->limit();
            $owner = $this->personalOwner($dashboard);
            $entries = match (true) {
                $dashboard->isTeamDashboard() => $this->top->forFeeds($this->feedIds($dashboard), $limit),
                $owner !== null => $this->top->for($owner, $limit),
                default => collect(),
            };
        } else {
            $query = $this->entries($widget, $dashboard);

            if ($query && $unreadOnly) {
                $query->where(fn (Builder $q) => $q
                    ->whereNotIn('entries.id', $this->readBy($viewer))
                    ->when($keep !== [], fn (Builder $q) => $q->orWhereIn('entries.id', $keep)));
            }

            $entries = $query?->limit($widget->limit())->get() ?? collect();
        }

        $entries = $this->withViewerState($entries, $viewer);

        if ($unreadOnly && $widget->type === 'top') {
            $entries = $entries->filter(fn (Entry $e) => ! $e->isRead() || in_array($e->id, $keep, true))
                ->take($widget->limit())->values();
        }

        return $entries;
    }

    /**
     * Whether a story may be opened from this dashboard: it's from one of the dashboard's
     * feeds, or (personal dashboards) a tag / starred widget includes it.
     */
    public function canShow(Dashboard $dashboard, int $entryId): bool
    {
        $fromFeeds = DB::table('entries')->where('id', $entryId)
            ->whereIn('feed_id', $this->feedIds($dashboard))->exists();

        if ($fromFeeds) {
            return true;
        }

        $owner = $this->personalOwner($dashboard);

        if ($owner === null) {
            return false;
        }

        foreach ($dashboard->widgets as $widget) {
            $visible = match ($widget->type) {
                'tag' => $this->query->taggedEntryIds($owner, (int) ($widget->config['tag_id'] ?? 0))->where('entry_tag.entry_id', $entryId)->exists(),
                'starred' => DB::table('entry_user')->where('user_id', $owner->id)->where('entry_id', $entryId)->whereNotNull('starred_at')->exists(),
                default => false,
            };

            if ($visible) {
                return true;
            }
        }

        return false;
    }

    /** Feed ids the dashboard draws from (subquery), optionally within one folder. */
    public function feedIds(Dashboard $dashboard, ?int $folderId = null): QueryBuilder
    {
        if ($dashboard->isTeamDashboard()) {
            return DB::table('subscriptions')->select('feed_id')->where('team_id', $dashboard->team_id)
                ->when($folderId !== null, fn (QueryBuilder $q) => $q->where('folder_id', $folderId));
        }

        $owner = $this->personalOwner($dashboard);

        // A personal dashboard without an owner can't exist (it's removed with the owner); show nothing.
        return $owner ? $this->query->subscribedFeedIds($owner, $folderId) : DB::table('subscriptions')->select('feed_id')->whereRaw('1 = 0');
    }

    private function personalOwner(Dashboard $dashboard): ?User
    {
        return $dashboard->isTeamDashboard() ? null : $dashboard->owner;
    }

    /** @return Builder<Entry>|null */
    private function entries(DashboardWidget $widget, Dashboard $dashboard): ?Builder
    {
        $config = $widget->config;
        $owner = $this->personalOwner($dashboard);
        $query = Entry::query()
            ->select('entries.*')
            ->with('feed:id,title,url,site_url')
            ->orderByDesc('entries.published_at')
            ->orderByDesc('entries.id');

        $feeds = $this->feedIds($dashboard);

        switch ($widget->type) {
            case 'feed':
                $query->whereIn('entries.feed_id', $feeds)->where('entries.feed_id', (int) ($config['feed_id'] ?? 0));
                break;
            case 'folder':
                $query->whereIn('entries.feed_id', $this->feedIds($dashboard, (int) ($config['folder_id'] ?? 0)));
                break;
            case 'search':
                if (trim((string) ($config['query'] ?? '')) === '') {
                    return null;
                }
                $query->whereIn('entries.feed_id', $feeds);
                $this->query->applySearch($query, trim((string) $config['query']));
                break;
            case 'watchlist':
                $query->whereIn('entries.feed_id', $feeds)->whereIn('entries.id', $this->watchlistEntryIds($dashboard));
                break;
            case 'tag':
                if ($owner === null) {
                    return null;
                }
                $query->whereIn('entries.id', $this->query->taggedEntryIds($owner, (int) ($config['tag_id'] ?? 0)));
                break;
            case 'starred':
                if ($owner === null) {
                    return null;
                }
                $query->whereIn('entries.id', DB::table('entry_user')->select('entry_id')->where('user_id', $owner->id)->whereNotNull('starred_at'));
                break;
            default:
                return null;
        }

        return $query;
    }

    private function watchlistEntryIds(Dashboard $dashboard): QueryBuilder
    {
        if (! $dashboard->isTeamDashboard()) {
            $owner = $this->personalOwner($dashboard);

            return $owner ? $this->query->watchlistEntryIds($owner) : DB::table('watchlist_hits')->select('entry_id')->whereRaw('1 = 0');
        }

        return DB::table('watchlist_hits')
            ->select('watchlist_hits.entry_id')
            ->join('watchlist_items', 'watchlist_items.id', '=', 'watchlist_hits.watchlist_item_id')
            ->where('watchlist_items.team_id', $dashboard->team_id);
    }

    /** @return Collection<int, Cve> */
    private function cves(Dashboard $dashboard, string $filter, int $limit): Collection
    {
        $mentions = DB::table('cve_entry')
            ->join('entries', 'entries.id', '=', 'cve_entry.entry_id')
            ->whereIn('entries.feed_id', $this->feedIds($dashboard))
            ->where('entries.published_at', '>=', now()->subDays(14))
            ->groupBy('cve_entry.cve_id')
            ->selectRaw('cve_entry.cve_id, count(*) as stories, max(entries.published_at) as last_mentioned_at');

        return Cve::query()
            ->joinSub($mentions, 'm', 'm.cve_id', '=', 'cves.id')
            ->when($filter === 'kev', fn ($q) => $q->where('in_kev', true))
            ->when($filter === 'critical', fn ($q) => $q->where('cvss_score', '>=', 9))
            ->orderByDesc('in_kev')
            ->orderByRaw('cvss_score is null, cvss_score desc')
            ->orderByDesc('m.last_mentioned_at')
            ->limit($limit)
            ->get(['cves.*', 'm.stories', 'm.last_mentioned_at']);
    }

    private function readBy(User $viewer): QueryBuilder
    {
        return DB::table('entry_user')->select('entry_id')->where('user_id', $viewer->id)->whereNotNull('read_at');
    }

    /**
     * @param  Collection<int, Entry>  $entries
     * @return Collection<int, Entry>
     */
    private function withViewerState(Collection $entries, ?User $viewer): Collection
    {
        $read = $viewer && $entries->isNotEmpty()
            ? DB::table('entry_user')->where('user_id', $viewer->id)->whereIn('entry_id', $entries->pluck('id'))
                ->whereNotNull('read_at')->pluck('entry_id')->flip()
            : collect();

        return $entries->each(fn (Entry $e) => $e->setAttribute('read_at', $read->has($e->id) ? now() : null));
    }
}
