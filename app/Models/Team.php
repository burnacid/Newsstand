<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A group of users sharing folders (and their feeds), dashboards, a watchlist,
 * alert channels and notes on stories.
 *
 * Roles: owner (one; deletes the team), admin (manages members and shared things), member.
 *
 * @property int $id
 * @property string $name
 * @property int|null $created_by
 */
#[Fillable(['name'])]
class Team extends Model
{
    public const ROLES = ['owner', 'admin', 'member'];

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /** @return HasMany<Folder, $this> */
    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class)->orderBy('position')->orderBy('name');
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasMany<WatchlistItem, $this> */
    public function watchlist(): HasMany
    {
        return $this->hasMany(WatchlistItem::class)->orderBy('vendor')->orderBy('product');
    }

    /** @return HasMany<AlertChannel, $this> */
    public function alertChannels(): HasMany
    {
        return $this->hasMany(AlertChannel::class)->orderBy('name');
    }

    /** @return HasMany<TeamInvitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function roleOf(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        return $this->members()->whereKey($user->id)->value('role');
    }

    public function isMember(?User $user): bool
    {
        return $this->roleOf($user) !== null;
    }

    /** Owners and admins manage members, team folders, the team watchlist and channels. */
    public function isAdmin(?User $user): bool
    {
        return in_array($this->roleOf($user), ['owner', 'admin'], true);
    }
}
