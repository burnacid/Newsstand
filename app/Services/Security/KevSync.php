<?php

namespace App\Services\Security;

use App\Jobs\EvaluateRules;
use App\Models\Cve;
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

        $newIds = $rows->pluck('cve_id')->reject(fn ($id) => $wasKev->has($id))->values();

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
}
