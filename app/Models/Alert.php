<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One alert for one channel. Unique per (channel, dedupe_key), so the same
 * story or CVE is never sent twice to the same place.
 *
 * @property int $id
 * @property int $alert_channel_id
 * @property string $dedupe_key
 * @property array<string, mixed> $payload
 * @property string $status
 * @property string|null $error
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable $created_at
 */
#[Fillable(['alert_channel_id', 'dedupe_key', 'payload', 'status', 'error', 'sent_at'])]
class Alert extends Model
{
    protected function casts(): array
    {
        return ['payload' => 'array', 'sent_at' => 'datetime'];
    }

    /** @return BelongsTo<AlertChannel, $this> */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(AlertChannel::class, 'alert_channel_id');
    }
}
