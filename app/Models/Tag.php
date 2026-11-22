<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A user's personal label for entries.
 *
 * @property int $id
 * @property int $user_id
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

    /** @return BelongsToMany<Entry, $this> */
    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(Entry::class)->withPivot('created_at');
    }
}
