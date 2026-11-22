<?php

namespace App\Actions\Feeds;

use App\Jobs\FetchFeed;
use App\Models\Feed;
use App\Models\Folder;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

class SubscribeToFeed
{
    /**
     * Subscribe the user, or (for a team folder the user manages) the team, to a feed.
     */
    public function handle(User $user, string $url, ?Folder $folder = null, ?string $title = null, ?string $siteUrl = null): Subscription
    {
        $teamFolder = $folder && $folder->team_id !== null;

        if ($teamFolder && ! $folder->team?->isAdmin($user)) {
            throw new AuthorizationException(__('Only team admins can add feeds to team folders.'));
        }

        if (! $teamFolder && $folder && $folder->user_id !== $user->id) {
            $folder = null;
        }

        $feed = Feed::firstOrCreateByUrl($url, array_filter([
            'title' => $title ? Str::limit($title, 250) : null,
            'site_url' => $siteUrl,
        ]));

        if ($teamFolder) {
            /** @var Team $team */
            $team = $folder->team;
            $max = $team->subscriptions()->where('folder_id', $folder->id)->max('position');

            $subscription = $team->subscriptions()->firstOrCreate(
                ['feed_id' => $feed->id],
                ['folder_id' => $folder->id, 'position' => $max === null ? 0 : (int) $max + 1],
            );
        } else {
            $subscription = $user->subscriptions()->firstOrCreate(
                ['feed_id' => $feed->id],
                ['folder_id' => $folder?->id, 'position' => self::nextPosition($user, $folder?->id)],
            );
        }

        // First subscriber of a brand-new feed: fetch it right away.
        if ($feed->last_fetched_at === null) {
            FetchFeed::dispatch($feed);
        }

        return $subscription;
    }

    /** New feeds go to the end of their folder. */
    public static function nextPosition(User $user, ?int $folderId): int
    {
        $max = $user->subscriptions()->where('folder_id', $folderId)->max('position');

        return $max === null ? 0 : (int) $max + 1;
    }
}
