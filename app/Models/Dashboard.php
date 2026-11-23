<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A page of widgets. Members (viewers / editors) see it without subscribing, with their own read state.
 *
 * Personal dashboard (team_id null): belongs to its owner; content comes from the owner's feeds,
 * folders, tags and stars. Removed with the owner.
 *
 * Team dashboard: belongs to the team; team owners/admins manage it and members view it. Content
 * comes from the team's feeds, folders and watchlist. owner_id is only "created by" (null once that
 * user is gone). Removed with the team.
 *
 * @property int $id
 * @property int|null $owner_id
 * @property int|null $team_id
 * @property string $name
 * @property string|null $description
 * @property string|null $share_token
 */
#[Fillable(['name', 'description', 'team_id'])]
class Dashboard extends Model
{
    public const ROLES = ['viewer', 'editor'];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<DashboardWidget, $this> */
    public function widgets(): HasMany
    {
        return $this->hasMany(DashboardWidget::class)->orderBy('position')->orderBy('id');
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function isTeamDashboard(): bool
    {
        return $this->team_id !== null;
    }

    /**
     * 'owner' (manage: rename, delete, share, move), 'editor', 'viewer' or null.
     * Team dashboards: team owners/admins manage, members view; a direct share can grant editing.
     */
    public function roleOf(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $role = $this->members()->whereKey($user->id)->value('role');

        if (! $this->isTeamDashboard()) {
            return $user->id === $this->owner_id ? 'owner' : $role;
        }

        $teamRole = $this->team?->roleOf($user);

        return match (true) {
            in_array($teamRole, ['owner', 'admin'], true) => 'owner',
            $role !== null => $role,
            $teamRole !== null => 'viewer',
            default => null,
        };
    }

    /** Who the content belongs to, for labels: the team's or the owner's name. */
    public function curatorName(): string
    {
        return $this->team->name ?? $this->owner->name ?? __('Newsstand');
    }

    public function publicUrl(): ?string
    {
        return $this->share_token ? route('dashboards.public', $this->share_token) : null;
    }
}
