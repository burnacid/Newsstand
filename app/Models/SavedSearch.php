<?php

namespace App\Models;

use App\Services\Reader\ReaderSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reader search kept in the sidebar.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $source a ReaderSource key ("all", "folder:3", ...)
 * @property string $query
 */
#[Fillable(['name', 'source', 'query'])]
class SavedSearch extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The reader with this search filled in. */
    public function url(): string
    {
        return route('reader', array_filter([
            's' => ReaderSource::parse($this->source)->key() === 'all' ? null : $this->source,
            'q' => $this->query,
        ]));
    }
}
