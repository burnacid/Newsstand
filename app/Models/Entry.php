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
            // Per-user state, joined in by EntryQuery (not columns of `entries`).
            'read_at' => 'datetime',
            'starred_at' => 'datetime',
            'read_later_at' => 'datetime',
        ];
    }

    public function isRead(): bool
    {
        return $this->getAttribute('read_at') !== null;
    }

    public function isStarred(): bool
    {
        return $this->getAttribute('starred_at') !== null;
    }

    public function isSavedForLater(): bool
    {
        return $this->getAttribute('read_later_at') !== null;
    }

    public function readingMinutes(): int
    {
        $words = str_word_count(strip_tags((string) ($this->content ?? $this->summary)));

        return max(1, (int) round($words / 230));
    }

    /** True when the lead image already appears inside the article body. */
    public function imageInContent(): bool
    {
        return $this->image_url !== null && str_contains((string) $this->content, $this->image_url);
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
