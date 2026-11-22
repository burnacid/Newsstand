<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Runtime settings (key/value, JSON-encoded). Read through a cache so lookups
 * on every request are free; writing clears it.
 *
 * @property string $key
 * @property string|null $value
 */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    private const CACHE_KEY = 'newsstand.settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever(self::CACHE_KEY, fn () => static::query()->pluck('value', 'key')->all());

        return array_key_exists($key, $all) ? json_decode((string) $all[$key], true) : $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
        Cache::forget(self::CACHE_KEY);
    }

    public static function forget(string $key): void
    {
        static::query()->whereKey($key)->delete();
        Cache::forget(self::CACHE_KEY);
    }
}
