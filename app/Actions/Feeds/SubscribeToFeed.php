<?php

namespace App\Actions\Feeds;

use App\Jobs\FetchFeed;
use App\Models\Feed;
use App\Models\Folder;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Str;

class SubscribeToFeed
{
    public function handle(User $user, string $url, ?Folder $folder = null, ?string $title = null, ?string $siteUrl = null): Subscription
    {
        if ($folder && $folder->user_id !== $user->id) {
            $folder = null;
        }

        $feed = Feed::firstOrCreateByUrl($url, array_filter([
            'title' => $title ? Str::limit($title, 250) : null,
            'site_url' => $siteUrl,
        ]));

        $subscription = $user->subscriptions()->firstOrCreate(
            ['feed_id' => $feed->id],
            ['folder_id' => $folder?->id, 'position' => self::nextPosition($user, $folder?->id)],
        );

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
