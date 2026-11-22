<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invite link (works for anyone with an account who has the link, until it expires).
 *
 * @property int $id
 * @property int $team_id
 * @property string $token
 * @property string $role
 * @property CarbonImmutable $expires_at
 */
#[Fillable(['token', 'role', 'expires_at', 'created_by'])]
class TeamInvitation extends Model
{
    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function url(): string
    {
        return route('teams.join', $this->token);
    }
}
