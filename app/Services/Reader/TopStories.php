<?php

namespace App\Services\Reader;

use App\Models\Entry;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Ranks the last couple of days of news by importance, one story per
 * cluster: how many sources cover it, whether it involves an actively
 * exploited or critical vulnerability, whether a rule highlighted it,
 * discounted by age.
 */
class TopStories
{
    public const WINDOW_HOURS = 48;

    public function __construct(private EntryQuery $query) {}

    /** @return Collection<int, Entry> each with top_score and top_sources attributes */
    public function for(User $user, int $limit = 50): Collection
    {
        $entries = $this->query->for($user, ReaderSource::all())
            ->where('entries.published_at', '>=', now()->subHours(self::WINDOW_HOURS))
            ->with(['feed:id,title,url,site_url', 'cves', 'cluster'])
            ->limit(2000)
            ->get();

        return $entries
            ->groupBy(fn (Entry $e) => $e->cluster_id ? 'c'.$e->cluster_id : 'e'.$e->id)
            ->map(function (Collection $group) {
                // The first report of the story represents it.
                /** @var Entry $lead */
                $lead = $group->sortBy([['published_at', 'asc'], ['id', 'asc']])->first();
                $lead->setAttribute('top_sources', $sources = $group->first()->cluster->size ?? 1);
                $lead->setAttribute('top_score', $this->score($group, $sources));

                return $lead;
            })
            ->sortByDesc('top_score')
            ->take($limit)
            ->values();
    }

    /** @param Collection<int, Entry> $group */
    public function score(Collection $group, int $sources): float
    {
        $cves = $group->flatMap->cves;
        $maxCvss = (float) $cves->max('cvss_score');
        $newest = $group->max('published_at');
        $hoursOld = max(0, now()->diffInMinutes($newest, true) / 60);

        $score = 1.0
            + ($sources - 1) * 3
            + ($cves->contains('in_kev', true) ? 6 : 0)
            + ($maxCvss >= 9 ? 4 : ($maxCvss >= 7 ? 2 : 0))
            + ($group->contains(fn (Entry $e) => $e->highlight() !== null) ? 3 : 0)
            + ($group->contains(fn (Entry $e) => $e->isStarred()) ? 1 : 0);

        // Halve the score every day.
        return round($score / (1 + $hoursOld / 24), 3);
    }
}
