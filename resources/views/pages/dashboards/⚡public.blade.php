<?php

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Entry;
use App\Services\Dashboards\WidgetContent;
use App\Services\Reader\EntryQuery;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Read-only public view of a dashboard via its share link. Headlines link to
 * the original articles; no article text, no read state, no editing.
 */
new #[Layout('layouts::public')] #[Title('Shared dashboard')] class extends Component {
    #[Locked]
    public int $dashboardId;

    public function mount(string $token): void
    {
        $dashboard = Dashboard::where('share_token', $token)->first();

        abort_if(! $dashboard || strlen($token) < 32, 404);

        $this->dashboardId = $dashboard->id;
    }

    #[Computed]
    public function dashboard(): Dashboard
    {
        return Dashboard::with(['owner', 'team', 'widgets'])->findOrFail($this->dashboardId);
    }

    #[Computed]
    public function contents(): array
    {
        $content = app(WidgetContent::class);

        return $this->dashboard->widgets
            ->mapWithKeys(fn (DashboardWidget $w) => [$w->id => $content->for($w, null)])
            ->all();
    }

    #[Computed]
    public function signals(): array
    {
        $ids = collect($this->contents)->flatten(1)->filter(fn ($i) => $i instanceof Entry)->pluck('id')->unique()->values()->all();

        return app(EntryQuery::class)->signalsFor($ids);
    }

    public function title(DashboardWidget $widget): string
    {
        return $widget->title ?: match ($widget->type) {
            'top' => __('Top stories'),
            'cves' => __('Vulnerabilities'),
            'search' => __('Search: :q', ['q' => $widget->config['query'] ?? '']),
            'starred' => __('Starred'),
            'watchlist' => __('Watchlist'),
            default => Str::headline($widget->type),
        };
    }
}; ?>

<div class="space-y-6">
    <div class="flex items-end justify-between gap-4 border-b border-zinc-300 pb-4 dark:border-zinc-700">
        <div>
            <div class="text-[0.7rem] font-medium tracking-[0.2em] text-zinc-500 uppercase">{{ now()->isoFormat('dddd, D MMMM YYYY') }}</div>
            <h1 class="mt-2 font-serif text-4xl font-semibold tracking-tight">{{ $this->dashboard->name }}</h1>
        </div>
        <span class="text-sm text-zinc-500">{{ __('Curated by :name', ['name' => $this->dashboard->curatorName()]) }}</span>
    </div>

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($this->dashboard->widgets as $widget)
            @php($items = $this->contents[$widget->id] ?? collect())
            <section wire:key="pw-{{ $widget->id }}" @class(['rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800/60', 'md:col-span-2' => $widget->size !== 'normal', 'xl:col-span-3' => $widget->size === 'full'])>
                <header class="flex items-center gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <flux:icon :name="$widget->icon()" variant="micro" class="text-emerald-700 dark:text-emerald-400" />
                    <h2 class="truncate font-serif text-lg font-semibold">{{ $this->title($widget) }}</h2>
                </header>
                <div class="divide-y divide-zinc-100 dark:divide-zinc-700/60">
                    @forelse ($items as $item)
                        @if ($item instanceof \App\Models\Cve)
                            <a href="{{ $item->nvdUrl() }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-2 px-4 py-2.5 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <span class="font-mono text-xs font-medium">{{ $item->cve_id }}</span>
                                @if ($item->cvss_score !== null)
                                    <flux:badge size="sm" :color="$item->color()">{{ number_format($item->cvss_score, 1) }}</flux:badge>
                                @endif
                                @if ($item->in_kev)
                                    <flux:badge size="sm" color="red" variant="solid" icon="fire">{{ __('Exploited') }}</flux:badge>
                                @endif
                                <span class="min-w-0 flex-1 truncate text-xs text-zinc-500">{{ $item->kev_product ? $item->kev_vendor.' '.$item->kev_product : Str::limit($item->description, 60) }}</span>
                            </a>
                        @else
                            <a href="{{ $item->url }}" target="_blank" rel="noopener noreferrer" class="block px-4 py-2.5 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <span class="flex items-center gap-1.5 text-[0.7rem] font-medium tracking-wide text-zinc-500 uppercase">
                                    <span class="truncate">{{ $item->feed->displayTitle() }}</span>
                                    <span>&middot;</span>
                                    <span class="shrink-0 normal-case">{{ $item->published_at->shortRelativeToNowDiffForHumans() }}</span>
                                </span>
                                <span class="mt-0.5 block font-serif leading-snug font-semibold">{{ $item->title }}</span>
                                <x-reader.signals :signals="array_merge($this->signals[$item->id] ?? [], ['sources' => 1])" class="mt-1" />
                            </a>
                        @endif
                    @empty
                        <p class="px-4 py-6 text-center text-sm text-zinc-500">{{ __('Nothing here yet.') }}</p>
                    @endforelse
                </div>
            </section>
        @endforeach
    </div>
</div>
