<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $size number of distinct feeds covering the story
 * @property CarbonImmutable $last_seen_at
 */
#[Fillable(['size', 'last_seen_at'])]
class StoryCluster extends Model
{
    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime'];
    }

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class, 'cluster_id');
    }

    public function recount(): void
    {
        $this->update([
            'size' => $this->entries()->distinct()->count('feed_id'),
            'last_seen_at' => $this->entries()->max('published_at') ?? now(),
        ]);
    }
}
