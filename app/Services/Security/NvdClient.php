<?php

namespace App\Services\Security;

use App\Exceptions\NvdRateLimited;
use App\Models\Cve;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Looks up CVSS score, severity and description in the NVD API (v2). */
class NvdClient
{
    /** Preferred CVSS versions, newest first. */
    private const METRICS = ['cvssMetricV40', 'cvssMetricV31', 'cvssMetricV30', 'cvssMetricV2'];

    /** @return bool whether NVD knows the CVE */
    public function enrich(Cve $cve): bool
    {
        $response = Http::withUserAgent(config('newsstand.fetch.user_agent'))
            ->timeout(30)
            ->withHeaders(array_filter(['apiKey' => config('newsstand.security.nvd_api_key')]))
            ->get(config('newsstand.security.nvd_url'), ['cveId' => $cve->cve_id]);

        if ($response->status() === 403 || $response->status() === 429) {
            throw new NvdRateLimited("NVD rate limit reached (HTTP {$response->status()}).");
        }

        $response->throw();

        $data = $response->json('vulnerabilities.0.cve');
        $cve->nvd_checked_at = now();

        if (! is_array($data)) {
            $cve->save();

            return false;
        }

        [$score, $vector] = $this->score(is_array($data['metrics'] ?? null) ? $data['metrics'] : []);

        $descriptions = is_array($data['descriptions'] ?? null) ? $data['descriptions'] : [];
        $description = collect($descriptions)->firstWhere('lang', 'en')['value'] ?? null;

        $cve->fill([
            'cvss_score' => $score,
            'severity' => Cve::severityFor($score),
            'cvss_vector' => $vector ? Str::limit($vector, 250, '') : null,
            'description' => $description ?? $cve->description,
            'published_at' => $data['published'] ?? null,
        ])->save();

        return true;
    }

    /**
     * Highest-version CVSS metric; prefer NVD's own ("Primary") score.
     *
     * @param  array<string, mixed>  $metrics
     * @return array{0: float|null, 1: string|null}
     */
    private function score(array $metrics): array
    {
        foreach (self::METRICS as $key) {
            $entries = collect(is_array($metrics[$key] ?? null) ? $metrics[$key] : [])->filter(fn ($m) => isset($m['cvssData']['baseScore']));

            if ($entries->isEmpty()) {
                continue;
            }

            $metric = $entries->firstWhere('type', 'Primary') ?? $entries->first();

            return [(float) $metric['cvssData']['baseScore'], $metric['cvssData']['vectorString'] ?? null];
        }

        return [null, null];
    }
}
