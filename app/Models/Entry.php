<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $feed_id
 * @property string $guid
 * @property string $guid_hash
 * @property string|null $url
 * @property string|null $url_hash
 * @property string|null $title
 * @property string|null $author
 * @property string|null $summary
 * @property string|null $content
 * @property bool $full_text_extracted
 * @property string|null $image_url
 * @property CarbonImmutable $published_at
 */
#[Fillable(['feed_id', 'guid', 'guid_hash', 'url', 'url_hash', 'title', 'author', 'summary', 'content', 'image_url', 'published_at'])]
class Entry extends Model
{
    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'full_text_extracted' => 'boolean',
        ];
    }

    /** @return BelongsTo<Feed, $this> */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(Feed::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['read_at', 'starred_at', 'read_later_at'])
            ->withTimestamps();
    }
}
