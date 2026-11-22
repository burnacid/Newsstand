<?php

namespace App\Actions\Reader;

use App\Models\Entry;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/** Adds and removes a user's tags on entries, and manages the tags themselves. */
class TagEntries
{
    /** @return bool whether the entry has the tag afterwards */
    public function toggle(User $user, Entry $entry, int $tagId): bool
    {
        $tag = $user->tags()->findOrFail($tagId);

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

    public function create(User $user, string $name, ?string $color = null): Tag
    {
        $name = Str::of($name)->squish()->limit(50, '')->toString();

        if ($name === '') {
            throw new InvalidArgumentException('A tag needs a name.');
        }

        $existing = $user->tags()->get()->first(fn (Tag $t) => mb_strtolower($t->name) === mb_strtolower($name));

        return $existing ?? $user->tags()->create([
            'name' => $name,
            'color' => in_array($color, Tag::COLORS, true) ? $color : Tag::COLORS[$user->tags()->count() % count(Tag::COLORS)],
        ]);
    }
}
