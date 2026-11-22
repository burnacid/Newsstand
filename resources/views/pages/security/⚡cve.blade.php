<?php

use App\Models\Cve;
use App\Services\Reader\EntryQuery;
use App\Services\Reader\ReaderSource;
use App\Services\Watchlist\WatchlistMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Everything Newsstand knows about one CVE: scores, exploitation, affected
 * products, and every story about it in the viewer's feeds.
 */
new #[Title('Vulnerability')] class extends Component {
    #[Locked]
    public int $cveRowId;

    public function mount(string $cveId): void
    {
        $cve = Cve::where('cve_id', strtoupper($cveId))->first();

        abort_if($cve === null, 404);

        $this->cveRowId = $cve->id;
    }

    #[Computed]
    public function cve(): Cve
    {
        return Cve::with('products')->findOrFail($this->cveRowId);
    }

    /** Stories about this CVE that the viewer can read, newest first. */
    #[Computed]
    public function stories(): Collection
    {
        return app(EntryQuery::class)
            ->for(Auth::user(), ReaderSource::all())
            ->whereIn('entries.id', DB::table('cve_entry')->select('entry_id')->where('cve_id', $this->cveRowId))
            ->with('feed:id,title,url')
            ->limit(100)
            ->get();
    }

    #[Computed]
    public function onWatchlist(): bool
    {
        return app(WatchlistMatcher::class)->cveIdsFor(Auth::user())->contains($this->cveRowId);
    }

    /** vendor => product names */
    #[Computed]
    public function productsByVendor(): Collection
    {
        return $this->cve->products
            ->groupBy('vendor_key')
            ->map(fn (Collection $rows) => ['vendor' => $rows->first()->vendor, 'products' => $rows->pluck('product')->unique()->values()])
            ->sortBy('vendor', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }
}; ?>

<div class="mx-auto w-full max-w-4xl space-y-8">
    @php($cve = $this->cve)

    <div>
        <flux:breadcrumbs>
            <flux:breadcrumbs.item :href="route('security')" wire:navigate>{{ __('Vulnerabilities') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ $cve->cve_id }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div class="mt-3 flex flex-wrap items-center gap-3">
            <h1 class="font-mono text-3xl font-semibold tracking-tight">{{ $cve->cve_id }}</h1>
            @if ($cve->cvss_score !== null)
                <flux:badge :color="$cve->color()">{{ ucfirst($cve->severity) }} {{ number_format($cve->cvss_score, 1) }}</flux:badge>
            @else
                <flux:badge color="zinc">{{ __('Not scored yet') }}</flux:badge>
            @endif
            @if ($cve->in_kev)
                <flux:badge color="red" variant="solid" icon="fire">{{ __('Actively exploited') }}</flux:badge>
            @endif
            @if ($this->onWatchlist)
                <flux:badge color="violet" icon="eye" data-test="cve-on-watchlist">{{ __('On your watchlist') }}</flux:badge>
            @endif
        </div>

        @if ($cve->kev_name)
            <p class="mt-3 font-serif text-xl">{{ $cve->kev_name }}</p>
        @endif
        @if ($cve->description)
            <p class="mt-3 leading-relaxed text-zinc-700 dark:text-zinc-300">{{ $cve->description }}</p>
        @endif

        <div class="mt-4 flex flex-wrap gap-2">
            <flux:button size="sm" icon:trailing="arrow-top-right-on-square" :href="$cve->nvdUrl()" target="_blank" rel="noopener noreferrer">{{ __('NVD') }}</flux:button>
            <flux:button size="sm" variant="ghost" icon="magnifying-glass" :href="route('reader', ['show' => 'all', 'q' => $cve->cve_id])" wire:navigate>{{ __('Search stories') }}</flux:button>
        </div>
    </div>

    <div class="grid gap-4 md:grid-cols-2">
        @if ($cve->in_kev)
            <flux:card class="space-y-2 border-red-200 dark:border-red-900/60">
                <flux:heading class="flex items-center gap-2"><flux:icon.fire variant="mini" class="text-red-600" /> {{ __('CISA: known exploited') }}</flux:heading>
                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                    @if ($cve->kev_vendor)<dt class="text-zinc-500">{{ __('Product') }}</dt><dd>{{ $cve->kev_vendor }} {{ $cve->kev_product }}</dd>@endif
                    @if ($cve->kev_added_on)<dt class="text-zinc-500">{{ __('Added') }}</dt><dd>{{ $cve->kev_added_on->isoFormat('D MMMM YYYY') }}</dd>@endif
                    @if ($cve->kev_due_on)<dt class="text-zinc-500">{{ __('Fix deadline') }}</dt><dd>{{ $cve->kev_due_on->isoFormat('D MMMM YYYY') }}</dd>@endif
                    <dt class="text-zinc-500">{{ __('Ransomware') }}</dt><dd>{{ $cve->kev_ransomware ? __('Known to be used') : __('Not known') }}</dd>
                </dl>
            </flux:card>
        @endif

        <flux:card class="space-y-2">
            <flux:heading>{{ __('Severity') }}</flux:heading>
            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 text-sm">
                <dt class="text-zinc-500">{{ __('CVSS') }}</dt><dd>{{ $cve->cvss_score !== null ? number_format($cve->cvss_score, 1).' ('.ucfirst($cve->severity).')' : __('not scored yet') }}</dd>
                @if ($cve->cvss_vector)<dt class="text-zinc-500">{{ __('Vector') }}</dt><dd class="font-mono text-xs break-all">{{ $cve->cvss_vector }}</dd>@endif
                @if ($cve->published_at)<dt class="text-zinc-500">{{ __('Published') }}</dt><dd>{{ $cve->published_at->isoFormat('D MMMM YYYY') }}</dd>@endif
                <dt class="text-zinc-500">{{ __('Checked') }}</dt><dd>{{ $cve->nvd_checked_at?->diffForHumans() ?? __('not yet') }}</dd>
            </dl>
        </flux:card>
    </div>

    @if ($this->productsByVendor->isNotEmpty())
        <section class="space-y-3">
            <flux:heading size="lg">{{ __('Affected products') }}</flux:heading>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->productsByVendor->take(30) as $group)
                    <div class="rounded-lg border border-zinc-200 px-3 py-2 text-sm dark:border-zinc-700">
                        <span class="font-medium">{{ \Illuminate\Support\Str::title($group['vendor']) }}</span>
                        <span class="text-zinc-500">· {{ $group['products']->take(6)->implode(', ') }}@if ($group['products']->count() > 6) {{ __('and :n more', ['n' => $group['products']->count() - 6]) }}@endif</span>
                    </div>
                @endforeach
            </div>
            <flux:text size="sm">
                <flux:link :href="route('watchlist')" wire:navigate>{{ __('Add a product to your watchlist') }}</flux:link>
                {{ __('to be told about vulnerabilities like this.') }}
            </flux:text>
        </section>
    @endif

    <section class="space-y-3">
        <flux:heading size="lg">{{ trans_choice(':count story in your feeds|:count stories in your feeds', $this->stories->count()) }}</flux:heading>
        <ol class="relative space-y-4 border-s border-zinc-200 ps-6 dark:border-zinc-700">
            @forelse ($this->stories as $story)
                <li class="relative" wire:key="story-{{ $story->id }}">
                    <span class="absolute -start-[1.81rem] top-1.5 size-3 rounded-full border-2 border-white bg-emerald-600 dark:border-zinc-900"></span>
                    <div class="text-xs text-zinc-500">{{ $story->published_at->isoFormat('D MMM YYYY, HH:mm') }} · {{ $story->feed->displayTitle() }}</div>
                    <a href="{{ route('reader', ['show' => 'all', 'e' => $story->id]) }}" wire:navigate class="font-serif text-lg leading-snug hover:underline">{{ $story->title }}</a>
                </li>
            @empty
                <li class="text-sm text-zinc-500">{{ __('None of your feeds mention it yet.') }}</li>
            @endforelse
        </ol>
    </section>
</div>
