<?php

namespace App\Services\Teams;

use App\Models\Folder;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The one place that answers "which feeds may this user read?" and "who reads
 * this feed?": a user's own subscriptions plus those of the teams they're in.
 */
class FeedAccess
{
    /** Team ids the user belongs to (subquery). */
    public static function teamIds(User $user): QueryBuilder
    {
        return DB::table('team_user')->select('team_id')->where('user_id', $user->id);
    }

    /**
     * Subscriptions visible to the user: personal ones and their teams'.
     *
     * @return Builder<Subscription>
     */
    public static function subscriptions(User $user): Builder
    {
        return Subscription::query()->where(fn (Builder $q) => $q
            ->where('subscriptions.user_id', $user->id)
            ->orWhereIn('subscriptions.team_id', self::teamIds($user)));
    }

    /**
     * Folders visible to the user: personal ones and their teams'.
     *
     * @return Builder<Folder>
     */
    public static function folders(User $user): Builder
    {
        return Folder::query()->where(fn (Builder $q) => $q
            ->where('folders.user_id', $user->id)
            ->orWhereIn('folders.team_id', self::teamIds($user)));
    }

    /**
     * Tags the user can use: their own and their teams'.
     *
     * @return Builder<Tag>
     */
    public static function tags(User $user): Builder
    {
        return Tag::query()->where(fn (Builder $q) => $q
            ->where('tags.user_id', $user->id)
            ->orWhereIn('tags.team_id', self::teamIds($user)));
    }

    /** Feed ids the user can read (subquery), optionally within one (personal or team) folder. */
    public static function feedIds(User $user, ?int $folderId = null): QueryBuilder
    {
        return DB::table('subscriptions')
            ->select('feed_id')
            ->where(fn (QueryBuilder $q) => $q
                ->where('user_id', $user->id)
                ->orWhereIn('team_id', self::teamIds($user)))
            ->when($folderId !== null, fn (QueryBuilder $q) => $q->where('folder_id', $folderId));
    }

    /**
     * Feed ids a team "watches" (subquery): its own feeds plus its members' personal
     * ones. Used to scope the team watchlist.
     */
    public static function teamFeedIds(int $teamId): QueryBuilder
    {
        return DB::table('subscriptions')
            ->select('feed_id')
            ->where(fn (QueryBuilder $q) => $q
                ->where('team_id', $teamId)
                ->orWhereIn('user_id', DB::table('team_user')->select('user_id')->where('team_id', $teamId)));
    }

    public static function canRead(User $user, int $feedId): bool
    {
        return self::feedIds($user)->where('feed_id', $feedId)->exists();
    }

    /**
     * Everyone who reads a feed, with the folder it sits in for them
     * (their own subscription wins over a team's).
     *
     * @return Collection<int, int|null> user_id => folder_id
     */
    public static function readersOf(int $feedId): Collection
    {
        $viaTeams = DB::table('subscriptions')
            ->join('team_user', 'team_user.team_id', '=', 'subscriptions.team_id')
            ->where('subscriptions.feed_id', $feedId)
            ->pluck('subscriptions.folder_id', 'team_user.user_id');

        $personal = DB::table('subscriptions')
            ->where('feed_id', $feedId)
            ->whereNotNull('user_id')
            ->pluck('folder_id', 'user_id');

        // union() keeps the first collection's value for duplicate keys: personal first.
        return $personal->union($viaTeams->all())->map(fn ($id) => $id === null ? null : (int) $id);
    }
}
