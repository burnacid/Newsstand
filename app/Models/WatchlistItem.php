<?php

namespace App\Models;

use App\Support\ProductName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A vendor (and optionally one of its products) a user runs.
 *
 * @property int $id
 * @property int $user_id
 * @property string $vendor
 * @property string|null $product
 * @property string $vendor_key
 * @property string|null $product_key
 * @property list<string>|null $aliases
 */
#[Fillable(['vendor', 'product', 'aliases'])]
class WatchlistItem extends Model
{
    protected static function booted(): void
    {
        static::saving(function (WatchlistItem $item) {
            $item->vendor_key = ProductName::key($item->vendor);
            $item->product_key = $item->product ? ProductName::key($item->product) : null;
        });
    }

    protected function casts(): array
    {
        return ['aliases' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Entry, $this> */
    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(Entry::class, 'watchlist_hits')->withPivot(['cve_id', 'reason', 'created_at']);
    }

    public function label(): string
    {
        return $this->product ? "{$this->vendor} {$this->product}" : $this->vendor;
    }

    /**
     * Words that identify this item in story text: the product (or the vendor,
     * when no product is set) plus any aliases.
     *
     * @return list<string>
     */
    public function terms(): array
    {
        return array_values(array_unique(array_filter(array_map('trim', [
            $this->product ?: $this->vendor,
            ...($this->aliases ?? []),
        ]), fn (string $t) => mb_strlen($t) >= 2)));
    }

    /**
     * Products (from NVD / KEV) this item covers: same vendor, and the product name
     * contains the watched product ("NetScaler" covers "NetScaler ADC and NetScaler Gateway").
     *
     * @param  Builder<CveProduct>  $query
     * @return Builder<CveProduct>
     */
    public function constrainProducts(Builder $query): Builder
    {
        $query->where('vendor_key', 'like', '%'.$this->vendor_key.'%');

        if ($this->product_key) {
            $query->where('product_key', 'like', '%'.$this->product_key.'%');
        }

        return $query;
    }

    /** @return Builder<CveProduct> */
    public function cveProducts(): Builder
    {
        return $this->constrainProducts(CveProduct::query());
    }
}
