<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A label for entries: a user's own (user_id), or a team's shared one (team_id). Any team member
 * can put a team tag on a story; team owners and admins create, rename and delete team tags.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $team_id
 * @property string $name
 * @property string $color
 */
#[Fillable(['name', 'color'])]
class Tag extends Model
{
    /** Flux badge colors offered when picking a tag color. */
    public const COLORS = ['zinc', 'red', 'orange', 'amber', 'lime', 'emerald', 'cyan', 'blue', 'violet', 'pink'];

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

    public function isTeamTag(): bool
    {
        return $this->team_id !== null;
    }

    /** @return BelongsToMany<Entry, $this> */
    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(Entry::class)->withPivot('created_at');
    }
}
