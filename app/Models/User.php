<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $is_admin
 * @property Carbon|null $disabled_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'digest_enabled' => 'boolean',
            'digest_sent_on' => 'date',
            'is_admin' => 'boolean',
            'disabled_at' => 'datetime',
        ];
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return HasMany<Folder, $this> */
    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class)->orderBy('position')->orderBy('name');
    }

    /** @return HasMany<Dashboard, $this> */
    public function dashboards(): HasMany
    {
        return $this->hasMany(Dashboard::class, 'owner_id')->orderBy('name');
    }

    /**
     * Dashboards other people shared with this user.
     *
     * @return BelongsToMany<Dashboard, $this>
     */
    public function sharedDashboards(): BelongsToMany
    {
        return $this->belongsToMany(Dashboard::class)->withPivot('role')->withTimestamps()->orderBy('name');
    }

    /** @return HasMany<AlertChannel, $this> */
    public function alertChannels(): HasMany
    {
        return $this->hasMany(AlertChannel::class)->orderBy('name');
    }

    /** @return HasMany<WatchlistItem, $this> */
    public function watchlist(): HasMany
    {
        return $this->hasMany(WatchlistItem::class)->orderBy('vendor')->orderBy('product');
    }

    /** @return HasMany<Rule, $this> */
    public function rules(): HasMany
    {
        return $this->hasMany(Rule::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<Tag, $this> */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class)->orderBy('name');
    }

    /** @return BelongsToMany<Feed, $this> */
    public function feeds(): BelongsToMany
    {
        return $this->belongsToMany(Feed::class, 'subscriptions')->withPivot(['folder_id', 'custom_title'])->withTimestamps();
    }

    /** @return BelongsToMany<Entry, $this> */
    public function entryStates(): BelongsToMany
    {
        return $this->belongsToMany(Entry::class)
            ->withPivot(['read_at', 'starred_at', 'read_later_at', 'highlight', 'muted_at'])
            ->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return (bool) $this->is_admin;
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /** Admins who can still sign in. */
    public static function activeAdminCount(): int
    {
        return static::query()->where('is_admin', true)->whereNull('disabled_at')->count();
    }

    /**
     * Get the user's initials
     */
    public function initials(): string
    {
        $initials = Str::initials($this->name, true);

        return Str::length($initials) > 1
            ? Str::substr($initials, 0, 1).Str::substr($initials, -1)
            : $initials;
    }
}
