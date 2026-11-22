<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A note on a story: private (team_id null) or shared with a team.
 *
 * @property int $id
 * @property int $entry_id
 * @property int $user_id
 * @property int|null $team_id
 * @property string $body
 */
#[Fillable(['entry_id', 'team_id', 'body'])]
class EntryNote extends Model
{
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

    /**
     * Notes this user may read: their own, and those shared with their teams.
     *
     * @param  Builder<EntryNote>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('user_id', $user->id)
            ->orWhereIn('team_id', DB::table('team_user')->select('team_id')->where('user_id', $user->id)));
    }
}
