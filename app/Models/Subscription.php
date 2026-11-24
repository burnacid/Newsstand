<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $team_id
 * @property int $feed_id
 * @property int|null $folder_id
 * @property string|null $custom_title
 * @property bool $full_text whether this subscriber wants full article text fetched
 */
#[Fillable(['feed_id', 'folder_id', 'custom_title', 'position', 'team_id', 'full_text'])]
class Subscription extends Model
{
    protected static function booted(): void
    {
        // Keep the feed's flag ("anyone wants full text") in step.
        static::saved(fn (Subscription $s) => $s->wasChanged('full_text') || $s->wasRecentlyCreated ? $s->feed?->syncFullText() : null);
        static::deleted(fn (Subscription $s) => $s->feed?->syncFullText());
    }

    protected function casts(): array
    {
        return ['full_text' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return BelongsTo<Feed, $this> */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(Feed::class);
    }

    /** @return BelongsTo<Folder, $this> */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function displayTitle(): string
    {
        return $this->custom_title ?: $this->feed->displayTitle();
    }
}
