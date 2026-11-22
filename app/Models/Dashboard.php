<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A page of widgets. Content comes from the owner's feeds, folders and tags;
 * members (viewers / editors) see it without subscribing, with their own read state.
 *
 * @property int $id
 * @property int $owner_id
 * @property string $name
 * @property string|null $description
 * @property string|null $share_token
 */
#[Fillable(['name', 'description'])]
class Dashboard extends Model
{
    public const ROLES = ['viewer', 'editor'];

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
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

    public function roleOf(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        if ($user->id === $this->owner_id) {
            return 'owner';
        }

        return $this->members()->whereKey($user->id)->value('role');
    }

    public function publicUrl(): ?string
    {
        return $this->share_token ? route('dashboards.public', $this->share_token) : null;
    }
}
