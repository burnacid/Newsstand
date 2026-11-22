<?php

namespace App\Models;

use App\Support\FeedTitle;
use App\Support\UrlNormalizer;
use Carbon\CarbonImmutable;
use Database\Factories\FeedFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A feed is global: it is fetched once, no matter how many users subscribe.
 *
 * @property int $id
 * @property string $url
 * @property string $url_hash
 * @property string|null $site_url
 * @property string|null $title
 * @property string|null $description
 * @property string|null $etag
 * @property string|null $last_modified
 * @property bool $full_text
 * @property int $fetch_interval
 * @property CarbonImmutable|null $last_fetched_at
 * @property CarbonImmutable|null $last_success_at
 * @property CarbonImmutable|null $next_fetch_at
 * @property int $error_count
 * @property string|null $last_error
 */
#[Fillable(['url', 'site_url', 'title', 'description', 'full_text'])]
class Feed extends Model
{
    /** @use HasFactory<FeedFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(function (Feed $feed) {
            $feed->url_hash = UrlNormalizer::hash($feed->url);
            $feed->title = FeedTitle::clean($feed->title);
        });
    }

    protected function casts(): array
    {
        return [
            'full_text' => 'boolean',
            'last_fetched_at' => 'datetime',
            'last_success_at' => 'datetime',
            'next_fetch_at' => 'datetime',
        ];
    }

    public static function findByUrl(string $url): ?self
    {
        return static::where('url_hash', UrlNormalizer::hash($url))->first();
    }

    /** @param array<string, mixed> $attributes */
    public static function firstOrCreateByUrl(string $url, array $attributes = []): self
    {
        return static::findByUrl($url)
            ?? static::create(['url' => UrlNormalizer::normalize($url), ...$attributes]);
    }

    /** @return HasMany<Entry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(Entry::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * Feeds that have subscribers and are due for a fetch.
     *
     * @param  Builder<Feed>  $query
     */
    public function scopeDue(Builder $query): void
    {
        $query->whereHas('subscriptions')
            ->where(fn (Builder $q) => $q->whereNull('next_fetch_at')->orWhere('next_fetch_at', '<=', now()))
            ->orderByRaw('next_fetch_at is not null, next_fetch_at');
    }

    public function isHealthy(): bool
    {
        return $this->error_count === 0;
    }

    public function displayTitle(): string
    {
        return $this->title ?: (parse_url($this->url, PHP_URL_HOST) ?: $this->url);
    }
}
