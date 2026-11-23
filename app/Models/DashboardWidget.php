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
 *   watchlist []                         stories about the owner's watched products
 * All types accept ['limit' => 3..20]. Team dashboards use the team's feeds, folders and
 * watchlist instead of the owner's, and have no tag or starred widgets.
 *
 * @property int $id
 * @property int $dashboard_id
 * @property string $type
 * @property string|null $title
 * @property array<string, mixed> $config
 * @property string $size normal (one column) | wide (two columns) | full (the whole row)
 * @property int $position
 */
#[Fillable(['type', 'title', 'config', 'size', 'position'])]
class DashboardWidget extends Model
{
    public const TYPES = ['feed', 'folder', 'search', 'tag', 'starred', 'top', 'cves', 'watchlist'];

    /** Personal collections; not available on team dashboards. */
    public const PERSONAL_TYPES = ['tag', 'starred'];

    public const SIZES = ['normal', 'wide', 'full'];

    protected $attributes = ['size' => 'normal'];

    protected function casts(): array
    {
        return ['config' => 'array'];
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
            'watchlist' => 'eye',
            default => 'squares-2x2',
        };
    }
}
