@props([
    // ['cves' => Collection<Cve>, 'sources' => int] from EntryQuery::signalsFor()
    'signals' => null,
    // compact: badges for list rows; full: CVE details under an article
    'variant' => 'compact',
])

@php
    $cves = collect($signals['cves'] ?? []);
    $sources = (int) ($signals['sources'] ?? 1);
    $kev = $cves->firstWhere('in_kev', true);
    $worst = $cves->whereNotNull('cvss_score')->sortByDesc('cvss_score')->first();
@endphp

@if ($variant === 'compact')
    @if ($kev || $worst || $sources > 1 || $cves->isNotEmpty())
        <span {{ $attributes->class('flex flex-wrap items-center gap-1') }}>
            @if ($kev)
                <flux:badge size="sm" color="red" variant="solid" icon="fire" :title="__('Actively exploited (CISA KEV)')">{{ __('Exploited') }}</flux:badge>
            @endif
            @if ($worst)
                <flux:badge size="sm" :color="$worst->color()" :title="__('Highest score: :id', ['id' => $worst->cve_id])">CVSS {{ number_format($worst->cvss_score, 1) }}</flux:badge>
            @endif
            @if ($cves->count() > 1)
                <flux:badge size="sm" color="zinc" :title="$cves->pluck('cve_id')->implode(', ')">{{ trans_choice(':count CVE|:count CVEs', $cves->count()) }}</flux:badge>
            @elseif ($cves->count() === 1 && ! $worst)
                <flux:badge size="sm" color="zinc">{{ $cves->first()->cve_id }}</flux:badge>
            @endif
            @if ($sources > 1)
                <flux:badge size="sm" color="emerald" icon="newspaper" :title="__('Covered by :count sources', ['count' => $sources])">{{ $sources }}</flux:badge>
            @endif
        </span>
    @endif
@else
    @if ($cves->isNotEmpty() || $sources > 1)
        <aside {{ $attributes->class('mt-8 rounded-lg border border-zinc-200 bg-zinc-50 p-4 text-sm dark:border-zinc-700 dark:bg-zinc-800/50') }}>
            @if ($sources > 1)
                <p class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                    <flux:icon.newspaper variant="micro" class="text-emerald-600 dark:text-emerald-400" />
                    {{ __('Covered by :count sources.', ['count' => $sources]) }}
                </p>
            @endif

            @if ($cves->isNotEmpty())
                <ul @class(['space-y-2', 'mt-3 border-t border-zinc-200 pt-3 dark:border-zinc-700' => $sources > 1])>
                    @foreach ($cves->take(10) as $cve)
                        <li class="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <a href="{{ $cve->nvdUrl() }}" target="_blank" rel="noopener noreferrer"
                                class="font-mono text-xs font-medium text-zinc-800 hover:underline dark:text-zinc-100">{{ $cve->cve_id }}</a>
                            @if ($cve->cvss_score !== null)
                                <flux:badge size="sm" :color="$cve->color()">{{ ucfirst($cve->severity) }} {{ number_format($cve->cvss_score, 1) }}</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">{{ __('Not scored yet') }}</flux:badge>
                            @endif
                            @if ($cve->in_kev)
                                <flux:badge size="sm" color="red" variant="solid" icon="fire">{{ __('Exploited') }}</flux:badge>
                                @if ($cve->kev_ransomware)
                                    <flux:badge size="sm" color="red">{{ __('Used in ransomware') }}</flux:badge>
                                @endif
                                @if ($cve->kev_due_on)
                                    <span class="text-xs text-zinc-500">{{ __('CISA fix deadline :date', ['date' => $cve->kev_due_on->isoFormat('D MMM YYYY')]) }}</span>
                                @endif
                            @endif
                            @if ($cve->kev_name || $cve->description)
                                <span class="basis-full text-xs text-zinc-500 dark:text-zinc-400">{{ \Illuminate\Support\Str::limit($cve->kev_name ?: $cve->description, 160) }}</span>
                            @endif
                        </li>
                    @endforeach
                    @if ($cves->count() > 10)
                        <li class="text-xs text-zinc-500">{{ __('and :count more', ['count' => $cves->count() - 10]) }}</li>
                    @endif
                </ul>
            @endif
        </aside>
    @endif
@endif
