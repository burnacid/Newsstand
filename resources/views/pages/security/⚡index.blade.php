<?php

use App\Models\Cve;
use App\Services\Watchlist\WatchlistMatcher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/*
 * Vulnerabilities overview: CVEs mentioned in your feeds, ranked by
 * exploitation and severity, plus what CISA recently added to KEV.
 */
new #[Title('Vulnerabilities')] class extends Component {
    use WithPagination;

    /** mentioned | kev | critical | high | kev_new | mine */
    #[Url(except: 'mentioned')]
    public string $filter = 'mentioned';

    #[Url(except: 30)]
    public int $days = 30;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function cves(): LengthAwarePaginator
    {
        $user = Auth::user();
        $since = now()->subDays(in_array($this->days, [7, 30, 90], true) ? $this->days : 30);

        // Stories this user can see that mention each CVE, within the period.
        $mentions = DB::table('cve_entry')
            ->join('entries', 'entries.id', '=', 'cve_entry.entry_id')
            ->whereIn('entries.feed_id', DB::table('subscriptions')->select('feed_id')->where('user_id', $user->id))
            ->where('entries.published_at', '>=', $since)
            ->groupBy('cve_entry.cve_id')
            ->selectRaw('cve_entry.cve_id, count(*) as stories, max(entries.published_at) as last_mentioned_at');

        $query = Cve::query();

        if ($this->filter === 'mine') {
            // Everything affecting watched products, mentioned in a story or not.
            $query->whereIn('cves.id', app(WatchlistMatcher::class)->cveIdsFor($user))
                ->leftJoinSub($mentions, 'm', 'm.cve_id', '=', 'cves.id')
                ->orderByDesc('in_kev')
                ->orderByRaw('cvss_score is null, cvss_score desc')
                ->orderByDesc('cves.id');
        } elseif ($this->filter === 'kev_new') {
            $query->where('in_kev', true)->where('kev_added_on', '>=', now()->subDays(14)->toDateString())
                ->leftJoinSub($mentions, 'm', 'm.cve_id', '=', 'cves.id')
                ->orderByDesc('kev_added_on');
        } else {
            $query->joinSub($mentions, 'm', 'm.cve_id', '=', 'cves.id')
                ->when($this->filter === 'kev', fn (Builder $q) => $q->where('in_kev', true))
                ->when($this->filter === 'critical', fn (Builder $q) => $q->where('cvss_score', '>=', 9))
                ->when($this->filter === 'high', fn (Builder $q) => $q->where('cvss_score', '>=', 7))
                ->orderByDesc('in_kev')
                ->orderByRaw('cvss_score is null, cvss_score desc')
                ->orderByDesc('m.last_mentioned_at');
        }

        if (($term = trim($this->search)) !== '') {
            $query->where(fn (Builder $q) => $q
                ->whereLike('cves.cve_id', "%{$term}%")
                ->orWhereLike('cves.kev_vendor', "%{$term}%")
                ->orWhereLike('cves.kev_product', "%{$term}%")
                ->orWhereLike('cves.description', "%{$term}%"));
        }

        return $query->select('cves.*', 'm.stories', 'm.last_mentioned_at')->paginate(25);
    }

    #[Computed]
    public function stats(): array
    {
        $user = Auth::user();

        $row = DB::table('cves')
            ->join('cve_entry', 'cve_entry.cve_id', '=', 'cves.id')
            ->join('entries', 'entries.id', '=', 'cve_entry.entry_id')
            ->whereIn('entries.feed_id', DB::table('subscriptions')->select('feed_id')->where('user_id', $user->id))
            ->where('entries.published_at', '>=', now()->subDays(7))
            ->selectRaw('count(distinct cves.id) as mentioned')
            ->selectRaw('count(distinct case when cves.in_kev = ? then cves.id end) as exploited', [true])
            ->selectRaw('count(distinct case when cves.cvss_score >= 9 then cves.id end) as critical')
            ->first();

        return [
            'mentioned' => (int) ($row->mentioned ?? 0),
            'exploited' => (int) ($row->exploited ?? 0),
            'critical' => (int) ($row->critical ?? 0),
            'kev_new' => Cve::where('in_kev', true)->where('kev_added_on', '>=', now()->subDays(7)->toDateString())->count(),
            'kev_total' => Cve::where('in_kev', true)->count(),
        ];
    }
}; ?>

<div class="mx-auto w-full max-w-6xl space-y-6">
    <div>
        <flux:heading size="xl" level="1">{{ __('Vulnerabilities') }}</flux:heading>
        <flux:text class="mt-1">{{ __('CVEs mentioned in your feeds, with severity from NVD and exploitation status from CISA.') }}</flux:text>
    </div>

    {{-- Stats --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach ([
            ['mentioned', __('CVEs in the news'), __('last 7 days'), 'zinc'],
            ['exploited', __('Actively exploited'), __('mentioned, last 7 days'), 'red'],
            ['critical', __('Critical (CVSS 9+)'), __('mentioned, last 7 days'), 'orange'],
            ['kev_new', __('Added to CISA KEV'), __('last 7 days'), 'red'],
        ] as [$key, $label, $hint, $color])
            <flux:card class="!p-4">
                <div @class(['font-serif text-3xl font-semibold', 'text-red-600 dark:text-red-400' => $color === 'red', 'text-orange-600 dark:text-orange-400' => $color === 'orange'])>
                    {{ number_format($this->stats[$key]) }}
                </div>
                <div class="mt-1 text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $label }}</div>
                <div class="text-xs text-zinc-500">{{ $hint }}</div>
            </flux:card>
        @endforeach
    </div>

    @if ($this->stats['kev_total'] === 0)
        <flux:callout icon="information-circle" :heading="__('The CISA KEV catalog has not been imported yet')">
            <flux:callout.text>
                {{ __('It syncs daily. To import it now, run') }} <code class="font-mono">php artisan security:sync-kev</code>.
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- Filters --}}
    <div class="flex flex-col gap-3 md:flex-row md:items-center">
        <flux:radio.group wire:model.live="filter" variant="segmented" size="sm">
            <flux:radio value="mentioned" :label="__('All mentioned')" />
            <flux:radio value="kev" :label="__('Exploited')" />
            <flux:radio value="critical" :label="__('Critical')" />
            <flux:radio value="high" :label="__('High+')" />
            <flux:radio value="kev_new" :label="__('New in KEV')" />
            <flux:radio value="mine" :label="__('My products')" data-test="filter-mine" />
        </flux:radio.group>

        <flux:spacer />

        @if ($filter !== 'kev_new')
            <flux:select wire:model.live="days" size="sm" class="md:max-w-36">
                <flux:select.option value="7">{{ __('Last 7 days') }}</flux:select.option>
                <flux:select.option value="30">{{ __('Last 30 days') }}</flux:select.option>
                <flux:select.option value="90">{{ __('Last 90 days') }}</flux:select.option>
            </flux:select>
        @endif
        <flux:input wire:model.live.debounce.400ms="search" size="sm" icon="magnifying-glass" :placeholder="__('CVE, vendor or product')" class="md:max-w-64" clearable />
    </div>

    {{-- List --}}
    <div class="space-y-2">
        @forelse ($this->cves as $cve)
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800/60" wire:key="cve-{{ $cve->id }}">
                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('security.cve', $cve->cve_id) }}" wire:navigate class="font-mono text-sm font-semibold hover:underline">{{ $cve->cve_id }}</a>
                    @if ($cve->cvss_score !== null)
                        <flux:badge size="sm" :color="$cve->color()">{{ ucfirst($cve->severity) }} {{ number_format($cve->cvss_score, 1) }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="zinc">{{ __('Not scored yet') }}</flux:badge>
                    @endif
                    @if ($cve->in_kev)
                        <flux:badge size="sm" color="red" variant="solid" icon="fire">{{ __('Exploited') }}</flux:badge>
                        @if ($cve->kev_ransomware)
                            <flux:badge size="sm" color="red">{{ __('Ransomware') }}</flux:badge>
                        @endif
                    @endif
                    @if ($cve->kev_vendor)
                        <span class="text-sm text-zinc-500">{{ $cve->kev_vendor }} · {{ $cve->kev_product }}</span>
                    @endif

                    <flux:spacer />

                    @if ($cve->stories)
                        <flux:button size="xs" variant="ghost" icon="newspaper" :href="route('reader', ['show' => 'all', 'q' => $cve->cve_id])" wire:navigate>
                            {{ trans_choice(':count story|:count stories', $cve->stories) }}
                        </flux:button>
                    @endif
                </div>

                @if ($cve->kev_name || $cve->description)
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">{{ \Illuminate\Support\Str::limit($cve->kev_name ? $cve->kev_name.'. '.$cve->description : $cve->description, 300) }}</p>
                @endif

                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500">
                    @if ($cve->last_mentioned_at)
                        <span>{{ __('Last in the news :time', ['time' => \Illuminate\Support\Carbon::parse($cve->last_mentioned_at)->diffForHumans()]) }}</span>
                    @endif
                    @if ($cve->kev_added_on)
                        <span>{{ __('Added to KEV :date', ['date' => $cve->kev_added_on->isoFormat('D MMM YYYY')]) }}</span>
                    @endif
                    @if ($cve->kev_due_on)
                        <span>{{ __('CISA fix deadline :date', ['date' => $cve->kev_due_on->isoFormat('D MMM YYYY')]) }}</span>
                    @endif
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
                <flux:icon.shield-check class="mx-auto size-8 text-zinc-400" />
                <flux:heading class="mt-3">{{ __('No vulnerabilities here') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Nothing matches these filters. Security feeds mentioning CVEs show up here automatically.') }}</flux:text>
            </div>
        @endforelse
    </div>

    {{ $this->cves->links() }}
</div>
