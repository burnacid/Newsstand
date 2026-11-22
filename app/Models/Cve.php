<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $cve_id
 * @property float|null $cvss_score
 * @property string|null $severity
 * @property string|null $cvss_vector
 * @property string|null $description
 * @property CarbonImmutable|null $published_at
 * @property CarbonImmutable|null $nvd_checked_at
 * @property bool $in_kev
 * @property string|null $kev_vendor
 * @property string|null $kev_product
 * @property string|null $kev_name
 * @property CarbonImmutable|null $kev_added_on
 * @property CarbonImmutable|null $kev_due_on
 * @property bool $kev_ransomware
 */
#[Fillable([
    'cve_id', 'cvss_score', 'severity', 'cvss_vector', 'description', 'published_at', 'nvd_checked_at',
    'in_kev', 'kev_vendor', 'kev_product', 'kev_name', 'kev_added_on', 'kev_due_on', 'kev_ransomware',
])]
class Cve extends Model
{
    protected function casts(): array
    {
        return [
            'cvss_score' => 'float',
            'published_at' => 'datetime',
            'nvd_checked_at' => 'datetime',
            'in_kev' => 'boolean',
            'kev_added_on' => 'date',
            'kev_due_on' => 'date',
            'kev_ransomware' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Entry, $this> */
    public function entries(): BelongsToMany
    {
        return $this->belongsToMany(Entry::class);
    }

    /** @return HasMany<CveProduct, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(CveProduct::class)->orderBy('vendor')->orderBy('product');
    }

    /** Severity bucket from the CVSS score (NVD's v3 ranges). */
    public static function severityFor(?float $score): ?string
    {
        return match (true) {
            $score === null => null,
            $score >= 9.0 => 'critical',
            $score >= 7.0 => 'high',
            $score >= 4.0 => 'medium',
            $score > 0.0 => 'low',
            default => 'none',
        };
    }

    /** Flux badge color for the severity. */
    public function color(): string
    {
        return match ($this->severity) {
            'critical' => 'red',
            'high' => 'orange',
            'medium' => 'amber',
            'low' => 'lime',
            default => 'zinc',
        };
    }

    public function nvdUrl(): string
    {
        return 'https://nvd.nist.gov/vuln/detail/'.$this->cve_id;
    }
}
