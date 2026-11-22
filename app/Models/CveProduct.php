<?php

namespace App\Models;

use App\Support\ProductName;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $cve_id
 * @property string $vendor
 * @property string $product
 * @property string $vendor_key
 * @property string $product_key
 * @property string $source
 */
#[Fillable(['cve_id', 'vendor', 'product', 'vendor_key', 'product_key', 'source'])]
class CveProduct extends Model
{
    public $timestamps = false;

    /** @return BelongsTo<Cve, $this> */
    public function cve(): BelongsTo
    {
        return $this->belongsTo(Cve::class);
    }

    /**
     * Store affected products for a CVE (duplicates are ignored).
     *
     * @param  iterable<array{0: string, 1: string}>  $products  [vendor, product] pairs
     * @return int how many were new
     */
    public static function record(int $cveId, iterable $products, string $source): int
    {
        $rows = [];
        foreach ($products as [$vendor, $product]) {
            $vendorKey = ProductName::key($vendor);
            $productKey = ProductName::key($product);

            if ($vendorKey === '' || $productKey === '') {
                continue;
            }

            $rows["{$vendorKey}:{$productKey}"] = [
                'cve_id' => $cveId,
                'vendor' => mb_substr($vendor, 0, 250),
                'product' => mb_substr($product, 0, 250),
                'vendor_key' => mb_substr($vendorKey, 0, 250),
                'product_key' => mb_substr($productKey, 0, 250),
                'source' => $source,
            ];
        }

        return $rows === [] ? 0 : DB::table('cve_products')->insertOrIgnore(array_values($rows));
    }
}
