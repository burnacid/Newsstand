<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Types and their config:
 *   feed     ['feed_id' => int]          latest stories from one of the owner's feeds
 *   folder   ['folder_id' => int]        latest stories from one of the owner's folders
 *   search   ['query' => string]         stories matching a saved search
 *   tag      ['tag_id' => int]           stories the owner tagged (curated lists)
 *   starred  []                          the owner's starred stories
 *   top      []                          top stories of the last 48 hours
 *   cves     ['filter' => kev|critical|mentioned]  vulnerabilities in the news
 * All types accept ['limit' => 3..20].
 *
 * @property int $id
 * @property int $dashboard_id
 * @property string $type
 * @property string|null $title
 * @property array<string, mixed> $config
 * @property bool $wide
 * @property int $position
 */
#[Fillable(['type', 'title', 'config', 'wide', 'position'])]
class DashboardWidget extends Model
{
    public const TYPES = ['feed', 'folder', 'search', 'tag', 'starred', 'top', 'cves'];

    protected function casts(): array
    {
        return ['config' => 'array', 'wide' => 'boolean'];
    }

    /** @return BelongsTo<Dashboard, $this> */
    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(Dashboard::class);
    }

    public function limit(): int
    {
        return max(3, min(20, (int) ($this->config['limit'] ?? 8)));
    }

    public function icon(): string
    {
        return match ($this->type) {
            'feed' => 'rss',
            'folder' => 'folder',
            'search' => 'magnifying-glass',
            'tag' => 'tag',
            'starred' => 'star',
            'top' => 'fire',
            'cves' => 'shield-exclamation',
            default => 'squares-2x2',
        };
    }
}
