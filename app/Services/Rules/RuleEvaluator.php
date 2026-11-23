<?php

namespace App\Services\Rules;

use App\Models\Entry;
use App\Models\Rule;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Alerts\AlertMessage;
use App\Services\Reader\EntryQuery;
use App\Services\Reader\EntryStates;
use App\Services\Reader\ReaderSource;
use App\Services\Teams\FeedAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Applies users' and teams' rules to stories. Each rule applies to a story at most once
 * (tracked in rule_entry), so a user can undo an action without it coming back.
 * Non-matches are not recorded: stories are re-evaluated when their CVE scores,
 * KEV status or source count change, and may match then.
 */
class RuleEvaluator
{
    /** Whether matching rules may send alerts (off while back-filling). */
    private bool $notify = true;

    public function __construct(private EntryStates $states) {}

    /**
     * Evaluate every subscriber's rules against these entries.
     *
     * @param  list<int>  $entryIds
     * @return int number of rule matches applied
     */
    public function evaluateEntries(array $entryIds): int
    {
        if ($entryIds === []) {
            return 0;
        }

        $entries = Entry::with(['cves', 'cluster'])->whereKey($entryIds)->get();
        $applied = 0;

        foreach ($entries->groupBy('feed_id') as $feedId => $feedEntries) {
            // Personal subscribers and members of teams that follow the feed.
            foreach (FeedAccess::readersOf((int) $feedId) as $userId => $folderId) {
                $rules = Rule::where('user_id', $userId)->where('enabled', true)->orderBy('position')->get();

                if ($rules->isEmpty()) {
                    continue;
                }

                $user = User::query()->whereKey($userId)->first();

                if (! $user) {
                    continue;
                }

                foreach ($feedEntries as $entry) {
                    $applied += $this->evaluate($user, $rules, $entry, $folderId);
                }
            }

            // Teams that share the feed run their team rules once per story.
            $teamFolders = DB::table('subscriptions')->where('feed_id', (int) $feedId)->whereNotNull('team_id')->pluck('folder_id', 'team_id');

            foreach ($teamFolders as $teamId => $folderId) {
                $rules = Rule::where('team_id', $teamId)->where('enabled', true)->orderBy('position')->get();

                foreach ($rules->isEmpty() ? [] : $feedEntries as $entry) {
                    $applied += $this->evaluate(null, $rules, $entry, $folderId === null ? null : (int) $folderId);
                }
            }
        }

        return $applied;
    }

    /**
     * Run one rule over the user's stories from the last N days ("apply to existing").
     *
     * @return int number of matches
     */
    public function applyToRecent(Rule $rule, int $days = 30): int
    {
        // Back-filling: apply highlights/tags/etc., but don't send alerts about old news.
        $this->notify = false;

        try {
            return $this->backfill($rule, $days);
        } finally {
            $this->notify = true;
        }
    }

    private function backfill(Rule $rule, int $days): int
    {
        if ($rule->isTeamRule()) {
            return $this->backfillTeam($rule, $days);
        }

        $user = $rule->user;

        if ($user === null) {
            return 0;
        }

        // Team folders first, so the user's own folder wins for feeds in both.
        $folders = FeedAccess::subscriptions($user)->orderByRaw('subscriptions.user_id is not null')->pluck('folder_id', 'feed_id');

        $applied = 0;

        app(EntryQuery::class)
            ->for($user, ReaderSource::all())
            ->where('entries.published_at', '>=', now()->subDays($days))
            ->with(['cves', 'cluster'])
            ->reorder()
            ->orderBy('entries.id')
            ->chunk(200, function (Collection $entries) use ($user, $rule, $folders, &$applied) {
                foreach ($entries as $entry) {
                    $applied += $this->evaluate($user, collect([$rule]), $entry, $folders[$entry->feed_id] ?? null);
                }
            });

        return $applied;
    }

    /** A team rule over the team's shared feeds from the last N days. */
    private function backfillTeam(Rule $rule, int $days): int
    {
        $folders = DB::table('subscriptions')->where('team_id', $rule->team_id)->pluck('folder_id', 'feed_id');
        $applied = 0;

        Entry::query()
            ->whereIn('feed_id', $folders->keys())
            ->where('published_at', '>=', now()->subDays($days))
            ->with(['cves', 'cluster'])
            ->orderBy('id')
            ->chunk(200, function (Collection $entries) use ($rule, $folders, &$applied) {
                foreach ($entries as $entry) {
                    $folderId = $folders[$entry->feed_id] ?? null;
                    $applied += $this->evaluate(null, collect([$rule]), $entry, $folderId === null ? null : (int) $folderId);
                }
            });

        return $applied;
    }

    /** Whether a single rule matches an entry (used by the rule editor preview, too). */
    public function matches(Rule $rule, Entry $entry, ?int $folderId): bool
    {
        $conditions = collect($rule->conditions)->filter(fn ($c) => in_array($c['type'] ?? null, Rule::CONDITION_TYPES, true));

        if ($conditions->isEmpty()) {
            return false;
        }

        $text = mb_strtolower(implode("\n", [$entry->title, $entry->summary, strip_tags((string) $entry->content)]));
        $title = mb_strtolower((string) $entry->title);

        $check = fn (array $c) => $this->condition($c, $entry, $text, $title, $folderId, $rule);

        return $rule->match === 'any' ? $conditions->contains($check) : $conditions->every($check);
    }

    /**
     * @param  User|null  $user  the rules' owner; null for team rules
     * @param  Collection<int, Rule>  $rules
     */
    private function evaluate(?User $user, Collection $rules, Entry $entry, ?int $folderId): int
    {
        $done = DB::table('rule_entry')->where('entry_id', $entry->id)->whereIn('rule_id', $rules->pluck('id'))->pluck('rule_id')->flip();
        $applied = 0;

        foreach ($rules as $rule) {
            if ($done->has($rule->id) || ! $this->matches($rule, $entry, $folderId)) {
                continue;
            }

            if ($rule->isTeamRule()) {
                $this->applyForTeam($rule, $entry);
            } elseif ($user !== null) {
                $this->apply($user, $rule, $entry);
            }

            DB::table('rule_entry')->insertOrIgnore(['rule_id' => $rule->id, 'entry_id' => $entry->id, 'created_at' => now()]);
            $rule->increment('matches_count');
            $applied++;
        }

        return $applied;
    }

    /** @param array{type?: string, value?: mixed} $c */
    private function condition(array $c, Entry $entry, string $text, string $title, ?int $folderId, Rule $rule): bool
    {
        $value = $c['value'] ?? null;
        $userId = $rule->user_id;

        return match ($c['type'] ?? null) {
            'keyword' => self::containsAny($text, $value),
            'not_keyword' => ! self::containsAny($text, $value),
            'title' => self::containsAny($title, $value),
            'regex' => self::regexMatches((string) $value, $text),
            'feed' => (int) $value === $entry->feed_id,
            'folder' => $folderId !== null && (int) $value === $folderId,
            'has_cve' => $entry->cves->isNotEmpty(),
            'cvss' => $entry->cves->contains(fn ($cve) => $cve->cvss_score !== null && $cve->cvss_score >= (float) $value),
            'kev' => $entry->cves->contains('in_kev', true),
            'watchlist' => DB::table('watchlist_hits')
                ->join('watchlist_items', 'watchlist_items.id', '=', 'watchlist_hits.watchlist_item_id')
                ->where(fn ($q) => $rule->isTeamRule()
                    ? $q->where('watchlist_items.team_id', $rule->team_id)
                    : $q->where('watchlist_items.user_id', $userId)
                        ->orWhereIn('watchlist_items.team_id', DB::table('team_user')->select('team_id')->where('user_id', $userId)))
                ->where('watchlist_hits.entry_id', $entry->id)
                ->exists(),
            'sources' => ($entry->cluster->size ?? 1) >= max(2, (int) $value),
            default => false,
        };
    }

    private function apply(User $user, Rule $rule, Entry $entry): void
    {
        $actions = $rule->actions;
        $now = now();

        $state = array_filter([
            'highlight' => in_array($actions['highlight'] ?? null, Rule::HIGHLIGHTS, true) ? $actions['highlight'] : null,
            'starred_at' => ! empty($actions['star']) ? $now : null,
            'read_later_at' => ! empty($actions['later']) ? $now : null,
            'read_at' => ! empty($actions['read']) || ! empty($actions['mute']) ? $now : null,
            'muted_at' => ! empty($actions['mute']) ? $now : null,
        ]);

        if ($state !== []) {
            $this->states->setColumns($user, $entry->id, $state);
        }

        // Personal rules may use the user's own tags and their teams' shared tags.
        if (! empty($actions['tag_id']) && ($tag = FeedAccess::tags($user)->whereKey((int) $actions['tag_id'])->first())) {
            $tag->entries()->syncWithoutDetaching([$entry->id => ['created_at' => $now]]);
        }

        if ($this->shouldAlert($rule, $entry)) {
            app(AlertDispatcher::class)->notifyChannels($user, $actions['alert'], $this->alertMessage($rule, $entry), 'entry:'.$entry->id);
        }
    }

    /** Team rule: a team tag, a highlight for every member, the team's alert channels. */
    private function applyForTeam(Rule $rule, Entry $entry): void
    {
        $actions = $rule->actions;
        $team = $rule->team;

        if (! $team) {
            return;
        }

        if (in_array($actions['highlight'] ?? null, Rule::HIGHLIGHTS, true)) {
            foreach ($team->members()->get() as $member) {
                $this->states->setColumns($member, $entry->id, ['highlight' => $actions['highlight']]);
            }
        }

        if (! empty($actions['tag_id']) && ($tag = Tag::query()->where('team_id', $team->id)->whereKey((int) $actions['tag_id'])->first())) {
            $tag->entries()->syncWithoutDetaching([$entry->id => ['created_at' => now()]]);
        }

        if ($this->shouldAlert($rule, $entry)) {
            app(AlertDispatcher::class)->notifyTeamChannels($team, $actions['alert'], $this->alertMessage($rule, $entry), 'entry:'.$entry->id);
        }
    }

    /** Alerts only for live, recent stories, never while back-filling a new rule. */
    private function shouldAlert(Rule $rule, Entry $entry): bool
    {
        $recent = $entry->published_at->greaterThanOrEqualTo(now()->subDays(config('newsstand.alerts.max_story_age_days')));

        return $this->notify && $recent && ! empty($rule->actions['alert']) && is_array($rule->actions['alert']);
    }

    private function alertMessage(Rule $rule, Entry $entry): AlertMessage
    {
        $level = $entry->cves->contains('in_kev', true) ? 'critical' : ($entry->cves->isNotEmpty() ? 'high' : 'info');

        return AlertMessage::forEntry($entry, 'Rule: '.$rule->name, $level);
    }

    /** Comma-separated words or phrases; any match counts. */
    public static function containsAny(string $haystack, mixed $value): bool
    {
        foreach (explode(',', mb_strtolower((string) $value)) as $needle) {
            $needle = trim($needle);

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** Case-insensitive; an invalid pattern never matches. */
    public static function regexMatches(string $pattern, string $subject): bool
    {
        if ($pattern === '') {
            return false;
        }

        return @preg_match(self::delimit($pattern), $subject) === 1;
    }

    public static function isValidRegex(string $pattern): bool
    {
        return $pattern !== '' && @preg_match(self::delimit($pattern), '') !== false;
    }

    private static function delimit(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~iu';
    }
}
