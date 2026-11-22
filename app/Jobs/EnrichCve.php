<?php

namespace App\Jobs;

use App\Exceptions\NvdRateLimited;
use App\Models\Cve;
use App\Services\Security\NvdClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\DB;

/** Fetch CVSS score and description from NVD, then re-run rules on stories mentioning it. */
class EnrichCve implements ShouldQueue
{
    use Queueable;

    public bool $deleteWhenMissingModels = true;

    /** Rate limiting releases the job back to the queue; keep trying for a day. */
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addDay();
    }

    public function __construct(public Cve $cve) {}

    /**
     * NVD rate limits (see AppServiceProvider: 'nvd').
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('nvd')];
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(NvdClient $nvd): void
    {
        $hadScore = $this->cve->cvss_score !== null;
        $productsBefore = $this->cve->products()->count();

        try {
            $nvd->enrich($this->cve);
        } catch (NvdRateLimited) {
            // NVD's limit is shared by everything on this IP; just come back later.
            $this->release(60);

            return;
        }

        // First score: stories may now match "CVSS at least" rules.
        // New affected products: stories may now concern someone's watchlist.
        $nowScored = $this->cve->fresh()?->cvss_score !== null;
        $newProducts = $this->cve->products()->count() > $productsBefore;

        if ((! $hadScore && $nowScored) || $newProducts) {
            $entryIds = DB::table('cve_entry')->where('cve_id', $this->cve->id)->pluck('entry_id')->all();

            if ($entryIds !== []) {
                EvaluateRules::dispatch($entryIds);
            }
        }
    }
}
