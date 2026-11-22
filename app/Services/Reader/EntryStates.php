<?php

namespace App\Services\Reader;

use App\Models\Entry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** Writes a user's per-entry state: read, starred, saved for later. */
class EntryStates
{
    /** @param list<int> $entryIds */
    public function markRead(User $user, array $entryIds, bool $read = true): void
    {
        $this->set($user, $entryIds, 'read_at', $read);
    }

    public function toggleStar(User $user, Entry $entry): bool
    {
        $this->set($user, [$entry->id], 'starred_at', $starred = ! $entry->isStarred());

        return $starred;
    }

    public function toggleLater(User $user, Entry $entry): bool
    {
        $this->set($user, [$entry->id], 'read_later_at', $later = ! $entry->isSavedForLater());

        return $later;
    }

    /**
     * Mark every unread entry matched by a reader query as read.
     *
     * @param  Builder<Entry>  $query  from EntryQuery::for()
     * @return int number of entries marked
     */
    public function markAllRead(User $user, Builder $query): int
    {
        $ids = (clone $query)
            ->whereNull('eu.read_at')
            ->reorder()
            ->pluck('entries.id')
            ->all();

        foreach (array_chunk($ids, 500) as $chunk) {
            $this->markRead($user, $chunk);
        }

        return count($ids);
    }

    /** @param list<int> $entryIds */
    private function set(User $user, array $entryIds, string $column, bool $on): void
    {
        if ($entryIds === []) {
            return;
        }

        $now = now();

        DB::table('entry_user')->upsert(
            array_map(fn (int $id) => [
                'user_id' => $user->id,
                'entry_id' => $id,
                $column => $on ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ], $entryIds),
            ['user_id', 'entry_id'],
            [$column, 'updated_at'],
        );
    }
}
