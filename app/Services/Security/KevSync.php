<?php

namespace App\Services\Security;

use App\Jobs\EvaluateRules;
use App\Models\Cve;
use App\Models\CveProduct;
use App\Models\WatchlistItem;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Alerts\AlertMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Imports CISA's Known Exploited Vulnerabilities catalog. */
class KevSync
{
    /** @return array{total: int, new: int} */
    public function sync(): array
    {
        $catalog = Http::withUserAgent(config('newsstand.fetch.user_agent'))
            ->timeout(60)
            ->get(config('newsstand.security.kev_url'))
            ->throw()
            ->json('vulnerabilities', []);

        $wasKev = Cve::where('in_kev', true)->pluck('cve_id')->flip();
        $now = now();

        $rows = collect(is_array($catalog) ? $catalog : [])
            ->filter(fn ($v) => is_array($v) && preg_match(CveExtractor::PATTERN, (string) ($v['cveID'] ?? '')) === 1)
            ->map(fn ($v) => [
                'cve_id' => strtoupper($v['cveID']),
                'in_kev' => true,
                'kev_vendor' => Str::limit($v['vendorProject'] ?? '', 250, '') ?: null,
                'kev_product' => Str::limit($v['product'] ?? '', 250, '') ?: null,
                'kev_name' => Str::limit($v['vulnerabilityName'] ?? '', 250, '') ?: null,
                'kev_added_on' => $v['dateAdded'] ?? null,
                'kev_due_on' => $v['dueDate'] ?? null,
                'kev_ransomware' => strtolower($v['knownRansomwareCampaignUse'] ?? '') === 'known',
                'description' => $v['shortDescription'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->values();

        DB::transaction(function () use ($rows) {
            foreach ($rows->chunk(500) as $chunk) {
                // Keep NVD's description if we already have one.
                Cve::upsert($chunk->all(), ['cve_id'], [
                    'in_kev', 'kev_vendor', 'kev_product', 'kev_name', 'kev_added_on', 'kev_due_on', 'kev_ransomware', 'updated_at',
                ]);
            }

            Cve::whereNull('description')->whereIn('cve_id', $rows->pluck('cve_id'))->each(
                fn (Cve $cve) => $cve->update(['description' => $rows->firstWhere('cve_id', $cve->cve_id)['description'] ?? null])
            );
        });

        // CISA's vendor/product names, for watchlist matching.
        $ids = Cve::whereIn('cve_id', $rows->pluck('cve_id'))->pluck('id', 'cve_id');
        foreach ($rows as $row) {
            if (isset($ids[$row['cve_id']]) && $row['kev_vendor'] && $row['kev_product']) {
                CveProduct::record($ids[$row['cve_id']], [[$row['kev_vendor'], $row['kev_product']]], 'kev');
            }
        }

        $newIds = $rows->pluck('cve_id')->reject(fn ($id) => $wasKev->has($id))->values();

        $this->alertWatchers(array_values(array_map(strval(...), $newIds->all())));

        // Stories mentioning newly exploited CVEs may now match "actively exploited" rules.
        $entryIds = DB::table('cve_entry')
            ->join('cves', 'cves.id', '=', 'cve_entry.cve_id')
            ->whereIn('cves.cve_id', $newIds)
            ->pluck('cve_entry.entry_id')
            ->unique()
            ->values()
            ->all();

        if ($entryIds !== []) {
            EvaluateRules::dispatch($entryIds);
        }

        return ['total' => $rows->count(), 'new' => $newIds->count()];
    }

    /**
     * "A product you watch was just added to KEV": alert owners of matching
     * watchlist items (with alerts on) whose channels subscribe to KEV alerts.
     * Only for entries CISA added recently, so a first import doesn't flood anyone.
     *
     * @param  list<string>  $newCveIds
     */
    private function alertWatchers(array $newCveIds): void
    {
        if ($newCveIds === []) {
            return;
        }

        $recent = Cve::whereIn('cve_id', $newCveIds)
            ->where('kev_added_on', '>=', now()->subDays(7)->toDateString())
            ->get()
            ->keyBy('id');

        if ($recent->isEmpty()) {
            return;
        }

        $alerts = app(AlertDispatcher::class);

        foreach (WatchlistItem::with('user')->where('alert_level', '!=', 'off')->get() as $item) {
            $matching = $item->cveProducts()->whereIn('cve_id', $recent->keys())->distinct()->pluck('cve_id');

            foreach ($matching as $cveId) {
                $cve = $recent[$cveId];
                $alerts->notify($item->user, 'kev', AlertMessage::forKev($cve, $item->label()), 'kev:'.$cve->cve_id);
            }
        }
    }
}
