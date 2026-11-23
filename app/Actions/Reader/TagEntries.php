<?php

namespace App\Actions\Reader;

use App\Models\Entry;
use App\Models\Tag;
use App\Models\Team;
use App\Models\User;
use App\Services\Teams\FeedAccess;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Adds and removes tags on entries (a user's own, or their teams' shared ones),
 * and creates tags.
 */
class TagEntries
{
    /** @return bool whether the entry has the tag afterwards */
    public function toggle(User $user, Entry $entry, int $tagId): bool
    {
        $tag = FeedAccess::tags($user)->findOrFail($tagId);

        if ($tag->entries()->whereKey($entry->id)->exists()) {
            $tag->entries()->detach($entry->id);

            return false;
        }

        $tag->entries()->attach($entry->id, ['created_at' => now()]);

        return true;
    }

    /** Create a tag (or reuse one with the same name) and put it on the entry. */
    public function createAndAttach(User $user, Entry $entry, string $name): Tag
    {
        $tag = $this->create($user, $name);
        $tag->entries()->syncWithoutDetaching([$entry->id => ['created_at' => now()]]);

        return $tag;
    }

    /** Create a personal tag, or reuse the user's tag with the same name. */
    public function create(User $user, string $name, ?string $color = null): Tag
    {
        return $this->createIn($user->tags(), $name, $color);
    }

    /** Create a shared team tag, or reuse the team's tag with the same name. */
    public function createForTeam(Team $team, string $name, ?string $color = null): Tag
    {
        return $this->createIn($team->tags(), $name, $color);
    }

    /** @param HasMany<Tag, User>|HasMany<Tag, Team> $tags */
    private function createIn(HasMany $tags, string $name, ?string $color): Tag
    {
        $name = Str::of($name)->squish()->limit(50, '')->toString();

        if ($name === '') {
            throw new InvalidArgumentException('A tag needs a name.');
        }

        $existing = $tags->get()->first(fn (Tag $t) => mb_strtolower($t->name) === mb_strtolower($name));

        return $existing ?? $tags->create([
            'name' => $name,
            'color' => in_array($color, Tag::COLORS, true) ? $color : Tag::COLORS[$tags->count() % count(Tag::COLORS)],
        ]);
    }
}
