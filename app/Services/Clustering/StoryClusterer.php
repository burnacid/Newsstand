<?php

namespace App\Services\Clustering;

use App\Models\Entry;
use App\Models\StoryCluster;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Groups stories from different feeds that cover the same news, so the
 * reader can show "covered by N sources". Database-agnostic on purpose:
 * candidates come from a time window and are compared in PHP.
 */
class StoryClusterer
{
    private const STOPWORDS = [
        // English
        'the', 'and', 'for', 'with', 'from', 'that', 'this', 'are', 'was', 'were', 'has', 'have', 'had', 'its', 'into',
        'over', 'after', 'about', 'than', 'but', 'not', 'you', 'your', 'how', 'why', 'what', 'who', 'new', 'now',
        'says', 'said', 'will', 'can', 'could', 'may', 'more', 'out', 'via', 'amid', 'all', 'any', 'our', 'their',
        // Dutch
        'het', 'een', 'van', 'voor', 'met', 'door', 'naar', 'bij', 'over', 'niet', 'zijn', 'wordt', 'worden', 'dat', 'die', 'deze', 'ook', 'nog',
    ];

    /** @return StoryCluster|null the cluster the entry ended up in */
    public function cluster(Entry $entry): ?StoryCluster
    {
        if ($entry->cluster_id !== null) {
            return $entry->cluster;
        }

        $match = $this->bestMatch($entry);

        if ($match === null) {
            return null;
        }

        return DB::transaction(function () use ($entry, $match) {
            $cluster = $match->cluster_id
                ? StoryCluster::find($match->cluster_id)
                : StoryCluster::create(['size' => 1, 'last_seen_at' => now()]);

            if ($match->cluster_id === null) {
                $match->forceFill(['cluster_id' => $cluster->id])->save();
            }

            $entry->forceFill(['cluster_id' => $cluster->id])->save();
            $cluster->recount();

            return $cluster;
        });
    }

    private function bestMatch(Entry $entry): ?Entry
    {
        $window = config('newsstand.clustering.window_hours');
        $published = $entry->published_at;

        /** @var Collection<int, Entry> $candidates */
        $candidates = Entry::query()
            ->select(['id', 'feed_id', 'cluster_id', 'title', 'url_hash', 'published_at'])
            ->where('feed_id', '!=', $entry->feed_id)
            ->whereKeyNot($entry->id)
            ->whereBetween('published_at', [$published->subHours($window), $published->addHours($window)])
            ->orderByDesc('published_at')
            ->limit(5000)
            ->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        // 1. Same article URL.
        if ($entry->url_hash && ($same = $candidates->firstWhere('url_hash', $entry->url_hash))) {
            return $same;
        }

        // 2. Same specific CVE (skip roundups that mention many).
        $cves = $entry->cves()->pluck('cves.id');
        if ($cves->isNotEmpty() && $cves->count() <= 3) {
            $shared = DB::table('cve_entry')
                ->whereIn('cve_id', $cves)
                ->whereIn('entry_id', $candidates->pluck('id'))
                ->whereNotIn('entry_id', function ($q) {
                    // Exclude roundups on the other side too.
                    $q->select('entry_id')->from('cve_entry')->groupBy('entry_id')->havingRaw('count(*) > 3');
                })
                ->value('entry_id');

            if ($shared) {
                return $candidates->firstWhere('id', $shared);
            }
        }

        // 3. Similar titles.
        $words = self::words($entry->title);
        if (count($words) < config('newsstand.clustering.min_shared_words')) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($candidates as $candidate) {
            $score = self::similarity($words, self::words($candidate->title));

            if ($score > $bestScore) {
                [$best, $bestScore] = [$candidate, $score];
            }
        }

        return $bestScore >= config('newsstand.clustering.min_similarity') ? $best : null;
    }

    /**
     * Overlap of meaningful words relative to the shorter title, requiring a
     * minimum number of shared words. Robust to headlines of different length.
     *
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    public static function similarity(array $a, array $b): float
    {
        $shared = count(array_intersect($a, $b));
        $shorter = min(count($a), count($b));

        if ($shorter === 0 || $shared < config('newsstand.clustering.min_shared_words')) {
            return 0.0;
        }

        return $shared / $shorter;
    }

    /** @return list<string> distinct meaningful lowercase words */
    public static function words(?string $title): array
    {
        $title = mb_strtolower((string) $title);
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $words = array_filter($tokens, fn (string $t) => (mb_strlen($t) >= 3 || ctype_digit($t)) && ! in_array($t, self::STOPWORDS, true));

        return array_values(array_unique($words));
    }
}
