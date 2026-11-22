<?php

namespace App\Actions\Feeds;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Drag-and-drop ordering of folders and of feeds within (and between) folders. */
class Organizer
{
    /**
     * The order feeds are shown in: manual position first, then title.
     *
     * @param  Collection<int, Subscription>  $subscriptions
     * @return Collection<int, Subscription>
     */
    public static function sortSubscriptions(Collection $subscriptions): Collection
    {
        return $subscriptions->sortBy([
            fn (Subscription $a, Subscription $b) => $a->position <=> $b->position,
            fn (Subscription $a, Subscription $b) => strcasecmp($a->displayTitle(), $b->displayTitle()),
        ])->values();
    }

    public function moveFolder(User $user, int $folderId, int $position): void
    {
        $ids = $user->folders()->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        if (! in_array($folderId, $ids, true)) {
            return;
        }

        $this->writePositions('folders', $this->reorder($ids, $folderId, $position));
    }

    /** Move a feed to $position inside $folderId (null = unfiled). */
    public function moveSubscription(User $user, int $subscriptionId, int $position, ?int $folderId): void
    {
        $subscription = $user->subscriptions()->find($subscriptionId);

        if (! $subscription || ($folderId !== null && ! $user->folders()->whereKey($folderId)->exists())) {
            return;
        }

        DB::transaction(function () use ($user, $subscription, $position, $folderId) {
            $subscription->update(['folder_id' => $folderId]);

            $siblings = self::sortSubscriptions(
                $user->subscriptions()->with('feed')->where('folder_id', $folderId)->get()
            )->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

            $this->writePositions('subscriptions', $this->reorder($siblings, $subscription->id, $position));
        });
    }

    /**
     * @param  array<int, int>  $ids  current order
     * @return list<int> new order with $id moved to $position
     */
    private function reorder(array $ids, int $id, int $position): array
    {
        $ids = array_values(array_filter($ids, fn ($other) => $other !== $id));
        array_splice($ids, max(0, min($position, count($ids))), 0, [$id]);

        return $ids;
    }

    /** @param list<int> $ids */
    private function writePositions(string $table, array $ids): void
    {
        foreach ($ids as $index => $id) {
            DB::table($table)->where('id', $id)->update(['position' => $index]);
        }
    }
}
