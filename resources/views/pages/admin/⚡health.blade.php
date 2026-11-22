<?php

use App\Jobs\FetchFeed;
use App\Models\Cve;
use App\Models\Entry;
use App\Models\Feed;
use App\Models\Setting;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Health · Administration')] class extends Component {
    public function mount(): void
    {
        $this->authorize('admin');
    }

    /** @return array{at: ?Carbon, ok: bool} */
    private function timestamp(string $key, int $okWithinMinutes): array
    {
        $value = Setting::get($key);
        $at = $value ? Carbon::parse($value) : null;

        return ['at' => $at, 'ok' => $at !== null && $at->greaterThan(now()->subMinutes($okWithinMinutes))];
    }

    #[Computed]
    public function checks(): array
    {
        $pendingJobs = rescue(fn () => Queue::size(), null, false);

        return [
            'scheduler' => $this->timestamp('scheduler.last_run_at', 3),
            'kev' => $this->timestamp('kev.last_synced_at', 36 * 60) + ['count' => Cve::where('in_kev', true)->count()],
            'queue' => ['driver' => config('queue.default'), 'pending' => $pendingJobs, 'ok' => $pendingJobs !== null && $pendingJobs < 500],
            'failed' => DB::table('failed_jobs')->count(),
            'feed_errors' => Feed::where('error_count', '>', 0)->whereHas('subscriptions')->count(),
            'nvd_pending' => Cve::whereNull('cvss_score')->whereHas('entries', fn ($q) => $q->where('published_at', '>=', now()->subDays(30)))->count(),
            'nvd_key' => (bool) config('newsstand.security.nvd_api_key'),
        ];
    }

    #[Computed]
    public function totals(): array
    {
        return [
            __('Users') => User::count(),
            __('Feeds') => Feed::whereHas('subscriptions')->count(),
            __('Stories') => Entry::count(),
            __('CVEs in stories') => DB::table('cve_entry')->distinct()->count('cve_id'),
        ];
    }

    #[Computed]
    public function failedJobs(): Collection
    {
        return DB::table('failed_jobs')->orderByDesc('failed_at')->limit(20)->get()->map(fn ($job) => (object) [
            'uuid' => $job->uuid,
            'name' => class_basename(json_decode($job->payload, true)['displayName'] ?? 'Job'),
            'error' => Str::limit(strtok((string) $job->exception, "\n"), 200),
            'failed_at' => Carbon::parse($job->failed_at),
        ]);
    }

    #[Computed]
    public function brokenFeeds(): Collection
    {
        return Feed::where('error_count', '>', 0)->whereHas('subscriptions')->withCount('subscriptions')
            ->orderByDesc('error_count')->limit(50)->get();
    }

    public function retryJob(string $uuid): void
    {
        $this->authorize('admin');
        Artisan::call('queue:retry', ['id' => [$uuid]]);
        unset($this->failedJobs, $this->checks);
        Flux::toast(text: __('Queued again.'));
    }

    public function forgetJob(string $uuid): void
    {
        $this->authorize('admin');
        Artisan::call('queue:forget', ['id' => $uuid]);
        unset($this->failedJobs, $this->checks);
    }

    public function flushFailed(): void
    {
        $this->authorize('admin');
        Artisan::call('queue:flush');
        unset($this->failedJobs, $this->checks);
    }

    public function retryFeed(int $id): void
    {
        $this->authorize('admin');
        FetchFeed::dispatch(Feed::findOrFail($id));
        Flux::toast(text: __('Fetch queued.'));
    }
}; ?>

<x-pages::admin.layout :heading="__('Health')" :subheading="__('Is everything running? Background work, data sources and feeds.')">
    @php($c = $this->checks)

    <div class="space-y-8">
        {{-- Status --}}
        <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @php($cards = [
                [__('Scheduler'), $c['scheduler']['ok'], $c['scheduler']['at'] ? __('last run :time', ['time' => $c['scheduler']['at']->diffForHumans()]) : __('never ran. Is `schedule:work` running?')],
                [__('Queue'), $c['queue']['ok'], $c['queue']['pending'] === null ? __('can’t reach the :driver queue', ['driver' => $c['queue']['driver']]) : trans_choice(':count job waiting (:driver)|:count jobs waiting (:driver)', $c['queue']['pending'], ['driver' => $c['queue']['driver']])],
                [__('Failed jobs'), $c['failed'] === 0, trans_choice(':count failed job|:count failed jobs', $c['failed'])],
                [__('CISA KEV'), $c['kev']['ok'], $c['kev']['at'] ? __(':count entries, synced :time', ['count' => number_format($c['kev']['count']), 'time' => $c['kev']['at']->diffForHumans()]) : __('not synced yet')],
                [__('NVD scores'), $c['nvd_pending'] < 50, trans_choice(':count CVE waiting for a score|:count CVEs waiting for a score', $c['nvd_pending']).($c['nvd_key'] ? '' : ' · '.__('no API key'))],
                [__('Feeds'), $c['feed_errors'] === 0, trans_choice(':count feed with errors|:count feeds with errors', $c['feed_errors'])],
            ])
            @foreach ($cards as [$label, $ok, $detail])
                <div class="flex items-start gap-3 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800/60" data-test="health-card">
                    @if ($ok)
                        <flux:icon.check-circle class="mt-0.5 size-5 shrink-0 text-emerald-600 dark:text-emerald-400" />
                    @else
                        <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-500" />
                    @endif
                    <div class="min-w-0">
                        <div class="font-medium">{{ $label }}</div>
                        <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $detail }}</div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="flex flex-wrap gap-x-8 gap-y-2 text-sm text-zinc-600 dark:text-zinc-400">
            @foreach ($this->totals as $label => $count)
                <span><span class="font-serif text-xl font-semibold text-zinc-900 dark:text-zinc-100">{{ number_format($count) }}</span> {{ $label }}</span>
            @endforeach
            @if (config('queue.default') === 'redis')
                <flux:link href="/horizon" target="_blank" class="ms-auto">{{ __('Open Horizon') }}</flux:link>
            @endif
        </div>

        {{-- Failed jobs --}}
        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <flux:heading>{{ __('Failed jobs') }}</flux:heading>
                @if ($this->failedJobs->isNotEmpty())
                    <flux:button size="sm" variant="ghost" icon="trash" wire:click="flushFailed" wire:confirm="{{ __('Delete all failed jobs?') }}">{{ __('Delete all') }}</flux:button>
                @endif
            </div>
            @forelse ($this->failedJobs as $job)
                <div class="flex items-start gap-3 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700" wire:key="job-{{ $job->uuid }}">
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ $job->name }} <span class="font-normal text-zinc-500">· {{ $job->failed_at->diffForHumans() }}</span></div>
                        <div class="truncate font-mono text-xs text-zinc-500" title="{{ $job->error }}">{{ $job->error }}</div>
                    </div>
                    <flux:button size="xs" variant="subtle" icon="arrow-path" wire:click="retryJob('{{ $job->uuid }}')" :tooltip="__('Retry')" />
                    <flux:button size="xs" variant="subtle" icon="x-mark" wire:click="forgetJob('{{ $job->uuid }}')" :tooltip="__('Delete')" />
                </div>
            @empty
                <flux:text>{{ __('No failed jobs.') }}</flux:text>
            @endforelse
        </section>

        {{-- Broken feeds --}}
        <section class="space-y-3">
            <flux:heading>{{ __('Feeds with errors') }}</flux:heading>
            @forelse ($this->brokenFeeds as $feed)
                <div class="flex items-start gap-3 rounded-lg border border-zinc-200 p-3 text-sm dark:border-zinc-700" wire:key="feed-{{ $feed->id }}">
                    <div class="min-w-0 flex-1">
                        <div class="font-medium">{{ $feed->displayTitle() }}
                            <span class="font-normal text-zinc-500">· {{ trans_choice(':count error in a row|:count errors in a row', $feed->error_count) }} · {{ trans_choice(':count subscriber|:count subscribers', $feed->subscriptions_count) }}</span>
                        </div>
                        <div class="truncate text-xs text-zinc-500">{{ $feed->url }}</div>
                        <div class="text-xs text-red-600 dark:text-red-400">{{ $feed->last_error }}</div>
                    </div>
                    <flux:button size="xs" variant="subtle" icon="arrow-path" wire:click="retryFeed({{ $feed->id }})" :tooltip="__('Fetch now')" />
                </div>
            @empty
                <flux:text>{{ __('All feeds are fetching fine.') }}</flux:text>
            @endforelse
        </section>
    </div>
</x-pages::admin.layout>
