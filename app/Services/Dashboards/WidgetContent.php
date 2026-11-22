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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves what a widget shows. Content is scoped to the dashboard OWNER
 * (their feeds, folders, tags, stars), so members see it without
 * subscribing; read state comes from the VIEWER (null on public links).
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
        $owner = $widget->dashboard->owner;
        $unreadOnly = $unreadOnly && $viewer !== null;

        if ($widget->type === 'cves') {
            return $this->cves($owner, (string) ($widget->config['filter'] ?? 'kev'), $widget->limit());
        }

        if ($widget->type === 'top') {
            // Rank first, then drop what the viewer read, so the order stays meaningful.
            $entries = $this->top->for($owner, $unreadOnly ? 50 : $widget->limit());
        } else {
            $query = $this->entries($widget, $owner);

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
     * Whether a story may be opened from this dashboard: it's from one of the
     * owner's feeds, or the dashboard has a tag / starred widget that includes it.
     */
    public function canShow(Dashboard $dashboard, int $entryId): bool
    {
        $owner = $dashboard->owner;

        $fromOwnersFeeds = DB::table('entries')->where('id', $entryId)
            ->whereIn('feed_id', $this->query->subscribedFeedIds($owner))->exists();

        if ($fromOwnersFeeds) {
            return true;
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

    /** @return Builder<Entry>|null */
    private function entries(DashboardWidget $widget, User $owner): ?Builder
    {
        $config = $widget->config;
        $query = Entry::query()
            ->select('entries.*')
            ->with('feed:id,title,url,site_url')
            ->orderByDesc('entries.published_at')
            ->orderByDesc('entries.id');

        $ownersFeeds = $this->query->subscribedFeedIds($owner);

        switch ($widget->type) {
            case 'feed':
                $query->whereIn('entries.feed_id', $ownersFeeds)->where('entries.feed_id', (int) ($config['feed_id'] ?? 0));
                break;
            case 'folder':
                $query->whereIn('entries.feed_id', $this->query->subscribedFeedIds($owner, (int) ($config['folder_id'] ?? 0)));
                break;
            case 'search':
                if (trim((string) ($config['query'] ?? '')) === '') {
                    return null;
                }
                $query->whereIn('entries.feed_id', $ownersFeeds);
                $this->query->applySearch($query, trim((string) $config['query']));
                break;
            case 'tag':
                $query->whereIn('entries.id', $this->query->taggedEntryIds($owner, (int) ($config['tag_id'] ?? 0)));
                break;
            case 'starred':
                $query->whereIn('entries.id', DB::table('entry_user')->select('entry_id')->where('user_id', $owner->id)->whereNotNull('starred_at'));
                break;
            default:
                return null;
        }

        return $query;
    }

    /** @return Collection<int, Cve> */
    private function cves(User $owner, string $filter, int $limit): Collection
    {
        $mentions = DB::table('cve_entry')
            ->join('entries', 'entries.id', '=', 'cve_entry.entry_id')
            ->whereIn('entries.feed_id', $this->query->subscribedFeedIds($owner))
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

    private function readBy(User $viewer): \Illuminate\Database\Query\Builder
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
