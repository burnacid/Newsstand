<?php

namespace App\Services\Digest;

use App\Models\Cve;
use App\Models\Entry;
use App\Models\User;
use App\Services\Reader\EntryQuery;
use App\Services\Reader\ReaderSource;
use App\Services\Reader\TopStories;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** What goes into a user's daily email: the last 24 hours of their feeds. */
class DigestBuilder
{
    public function __construct(
        private TopStories $top,
        private EntryQuery $query,
    ) {}

    /**
     * @return array{top: Collection<int, Entry>, exploited: Collection<int, Cve>, highlighted: Collection<int, Entry>}|null
     *                                                                                                                       null when there's nothing worth sending
     */
    public function for(User $user): ?array
    {
        $since = now()->subDay();

        $top = $this->top->for($user, 30)
            ->filter(fn (Entry $e) => $e->published_at->greaterThanOrEqualTo($since))
            ->take(8)
            ->values();

        $exploited = Cve::query()
            ->where('in_kev', true)
            ->whereIn('id', DB::table('cve_entry')
                ->join('entries', 'entries.id', '=', 'cve_entry.entry_id')
                ->whereIn('entries.feed_id', $this->query->subscribedFeedIds($user))
                ->where('entries.published_at', '>=', $since)
                ->select('cve_entry.cve_id'))
            ->orderByRaw('cvss_score is null, cvss_score desc')
            ->limit(10)
            ->get();

        $highlighted = $this->query->for($user, ReaderSource::all())
            ->whereNotNull('eu.highlight')
            ->where('entries.published_at', '>=', $since)
            ->with('feed:id,title,url')
            ->limit(10)
            ->get();

        if ($top->isEmpty() && $exploited->isEmpty() && $highlighted->isEmpty()) {
            return null;
        }

        return compact('top', 'exploited', 'highlighted');
    }
}
