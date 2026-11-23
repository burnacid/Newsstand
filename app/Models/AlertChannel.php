<?php

namespace App\Models;

use App\Services\Alerts\Senders\PushSender;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A destination for alerts. Config holds the webhook URL / server / token and
 * is encrypted at rest.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $team_id
 * @property string $type
 * @property string $name
 * @property array<string, mixed> $config
 * @property list<string>|null $events
 * @property bool $enabled
 * @property CarbonImmutable|null $last_sent_at
 * @property string|null $last_error
 */
#[Fillable(['type', 'name', 'config', 'events', 'enabled'])]
#[Hidden(['config'])]
class AlertChannel extends Model
{
    public const TYPES = ['push', 'email', 'ntfy', 'gotify', 'slack', 'teams', 'discord'];

    /** Automatic triggers a channel can subscribe to (rules pick channels themselves). */
    public const EVENTS = ['watchlist', 'kev'];

    /** New channels are on (matches the column default, so fresh models agree with the database). */
    protected $attributes = ['enabled' => true];

    protected function casts(): array
    {
        return [
            'config' => 'encrypted:array',
            'events' => 'array',
            'enabled' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

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

    /** @return HasMany<Alert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function wants(string $event): bool
    {
        return $this->enabled && in_array($event, $this->events ?? [], true);
    }

    public static function label(string $type): string
    {
        return match ($type) {
            'push' => 'Push notification',
            'email' => 'Email',
            'ntfy' => 'ntfy',
            'gotify' => 'Gotify',
            'slack' => 'Slack',
            'teams' => 'Microsoft Teams',
            'discord' => 'Discord',
            default => $type,
        };
    }

    /** A short, non-secret description of where alerts go. */
    public function destination(): string
    {
        $c = $this->config;

        return match ($this->type) {
            'push' => trans_choice('{0} No devices yet|{1} :count device|[2,*] :count devices', $count = PushSender::devices($this)->count(), ['count' => $count])
                .($this->team_id ? ' '.__('in the team') : ''),
            'email' => (string) ($c['address'] ?? ''),
            'ntfy' => rtrim((string) ($c['server'] ?? 'https://ntfy.sh'), '/').'/'.($c['topic'] ?? ''),
            'gotify' => (string) ($c['url'] ?? ''),
            default => (string) (parse_url((string) ($c['url'] ?? ''), PHP_URL_HOST) ?: ''),
        };
    }
}
