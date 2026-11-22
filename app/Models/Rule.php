<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's rule: when a story matches the conditions, apply the actions.
 *
 * Conditions: list of ['type' => ..., 'value' => ...]. Types:
 *   keyword      any of the comma-separated words/phrases in title or text
 *   not_keyword  none of them
 *   title        any of the words/phrases in the title only
 *   regex        a regular expression on title and text
 *   feed         story is from feed id
 *   folder       story is from a feed in folder id
 *   has_cve      story mentions a CVE
 *   cvss         a mentioned CVE has CVSS >= value
 *   kev          a mentioned CVE is actively exploited (CISA KEV)
 *   sources      covered by at least value feeds
 *   watchlist    concerns something on the owner's watchlist
 *
 * Actions: ['highlight' => color|null, 'tag_id' => int|null, 'star' => bool,
 *           'later' => bool, 'read' => bool, 'mute' => bool,
 *           'alert' => list<int> alert channel ids]
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property bool $enabled
 * @property string $match
 * @property list<array<string, mixed>> $conditions
 * @property array<string, mixed> $actions
 * @property int $position
 * @property int $matches_count
 */
#[Fillable(['name', 'enabled', 'match', 'conditions', 'actions', 'position'])]
class Rule extends Model
{
    public const CONDITION_TYPES = ['keyword', 'not_keyword', 'title', 'regex', 'feed', 'folder', 'has_cve', 'cvss', 'kev', 'sources', 'watchlist'];

    public const HIGHLIGHTS = ['red', 'orange', 'amber', 'emerald', 'blue', 'violet'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'conditions' => 'array',
            'actions' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Whether evaluating this rule needs CVE / cluster data that may arrive later. */
    public function dependsOnSignals(): bool
    {
        return collect($this->conditions)->contains(fn ($c) => in_array($c['type'] ?? null, ['has_cve', 'cvss', 'kev', 'sources', 'watchlist'], true));
    }
}
