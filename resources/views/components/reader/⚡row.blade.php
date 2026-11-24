<?php

use App\Actions\Reader\TagEntries;
use App\Models\Entry;
use App\Services\Reader\EntryQuery;
use App\Services\Teams\FeedAccess;
use App\Services\Watchlist\WatchlistMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * One story in the reader's list, cards or river.
 *
 * The reader passes everything the row shows and keys the row on a hash of it
 * (see the reader's rowKey()), so when the reader re-renders, rows that did not
 * change are skipped and only changed rows are drawn again. Actions go to the
 * reader ($parent.…), except tagging: that happens here so the open tag menu
 * stays open while you tick tags.
 */
new class extends Component {
    #[Locked]
    public int $entryId;

    /** 'list', 'cards' or 'river'. */
    #[Locked]
    public string $layout = 'list';

    #[Locked]
    public bool $selected = false;

    /** River: full text shown. */
    #[Locked]
    public bool $expanded = false;

    /** Listed under "Top stories" (shows the source count). */
    #[Locked]
    public bool $top = false;

    /** Top stories: how many sources covered it. */
    #[Locked]
    public int $topSources = 1;

    // Handed over by the reader for the first render; looked up again for the row's own requests.
    protected ?Entry $preloaded = null;

    /** Whether the reader handed over signals and tags (only on the request that mounts the row). */
    protected bool $handedOver = false;

    protected ?array $preloadedSignals = null;

    protected ?Collection $preloadedTags = null;

    protected ?Collection $preloadedUserTags = null;

    public function mount(Entry $entry, ?array $signals = null, mixed $tags = [], ?Collection $userTags = null): void
    {
        $this->entryId = $entry->id;
        $this->topSources = (int) ($entry->top_sources ?? 1);
        $this->preloaded = $entry;
        $this->preloadedSignals = $signals;
        $this->preloadedTags = collect($tags);
        $this->preloadedUserTags = $userTags;
        $this->handedOver = true;
    }

    public function toggleTag(int $tagId, ?int $id = null): void
    {
        if ($entry = $this->entry()) {
            app(TagEntries::class)->toggle(Auth::user(), $entry, $tagId);
            $this->dispatch('tags-changed');
        }
    }

    public function addTag(string $name, ?int $id = null): void
    {
        if (! $entry = $this->entry()) {
            return;
        }

        try {
            app(TagEntries::class)->createAndAttach(Auth::user(), $entry, $name);
        } catch (\InvalidArgumentException) {
            return;
        }

        $this->dispatch('tags-changed');
    }

    public function entry(): ?Entry
    {
        return $this->preloaded ??= app(EntryQuery::class)->find(Auth::user(), $this->entryId);
    }

    public function signals(): ?array
    {
        if (! $this->handedOver) {
            $this->preloadedSignals = $this->lookUpSignals();
            $this->handedOver = true;
        }

        return $this->preloadedSignals;
    }

    public function tags(): Collection
    {
        return $this->preloadedTags ??= collect(app(EntryQuery::class)->tagsFor(Auth::user(), [$this->entryId])[$this->entryId] ?? []);
    }

    public function userTags(): Collection
    {
        return $this->preloadedUserTags ??= FeedAccess::tags(Auth::user())->with('team:id,name')
            ->orderByRaw('tags.team_id is not null')->orderBy('tags.name')->get();
    }

    private function lookUpSignals(): ?array
    {
        $signals = app(EntryQuery::class)->signalsFor([$this->entryId])[$this->entryId] ?? [];
        $watch = app(WatchlistMatcher::class)->labelsFor(Auth::user(), [$this->entryId])[$this->entryId] ?? null;

        if ($watch !== null) {
            $signals['watch'] = $watch;
        }

        return $signals ?: null;
    }
}; ?>

@php
    $item = $this->entry();
    $signals = $this->signals();
    $tags = $this->tags();
@endphp

{{-- One plain root element: Livewire tracks a child component by its root tag. --}}
<div>
@if (! $item)
    {{-- The story is gone (unsubscribed or pruned). --}}
@elseif ($layout === 'river')
    <div data-river-id="{{ $item->id }}" data-url="{{ $item->url }}"
        x-data="{ read: @js($item->isRead()) }"
        x-bind:class="read && 'bg-zinc-100 dark:bg-zinc-950/60'"
        @class([
            'border-b border-zinc-200 transition-colors duration-500 dark:border-zinc-800',
            'bg-zinc-100 dark:bg-zinc-950/60' => $item->isRead(),
            'border-s-4 highlight-'.$item->highlight() => $item->highlight(),
        ])>
        <x-reader.article :entry="$item" variant="river" :expanded="$expanded" :signals="$signals" call="$parent.">
            <x-slot:badges>
                <span x-show="! read" class="rounded-full bg-emerald-100 px-2 py-0.5 text-[0.65rem] font-semibold tracking-wide text-emerald-800 uppercase dark:bg-emerald-900/60 dark:text-emerald-300">{{ __('New') }}</span>
                @if ($item->isStarred())
                    <flux:icon.star variant="micro" class="text-amber-500" />
                @endif
                <x-reader.signals :signals="$signals" />
                <x-reader.tag-chips :tags="$tags" />
            </x-slot:badges>
            <x-slot:actions>
                <span class="ms-auto flex items-center gap-0.5">
                    <x-reader.tag-menu :entry-id="$item->id" :tags="$this->userTags()" :selected="$tags" size="xs" />
                    <flux:button size="xs" variant="ghost" icon="star" :icon:variant="$item->isStarred() ? 'solid' : 'outline'"
                        wire:click="$parent.toggleStar({{ $item->id }})" @class(['!text-amber-500' => $item->isStarred()]) :tooltip="__('Star') . ' (s)'" />
                    <flux:button size="xs" variant="ghost" icon="bookmark" :icon:variant="$item->isSavedForLater() ? 'solid' : 'outline'"
                        wire:click="$parent.toggleLater({{ $item->id }})" :tooltip="__('Read later') . ' (l)'" />
                    <flux:button size="xs" variant="ghost" :icon="$item->isRead() ? 'envelope' : 'envelope-open'"
                        wire:click="$parent.toggleRead({{ $item->id }})" :tooltip="__('Mark read / unread') . ' (m)'" />
                </span>
            </x-slot:actions>
        </x-reader.article>
    </div>
@else
    {{-- A div, not a button: the row holds links (tag chips). The title is the button. --}}
    <div wire:click="$parent.select({{ $item->id }})"
        data-entry-id="{{ $item->id }}" data-url="{{ $item->url }}"
        @if ($selected) aria-current="true" @endif
        @class([
            'group relative flex w-full cursor-pointer gap-3 border-b border-zinc-100 px-4 py-3 text-start transition-colors dark:border-zinc-800',
            'bg-emerald-50 dark:bg-emerald-950/40' => $selected,
            'hover:bg-zinc-50 dark:hover:bg-zinc-800/60' => ! $selected && ! $item->highlight(),
            'highlight-'.$item->highlight() => $item->highlight() && ! $selected,
        ])>
        @if ($item->highlight() && ! $selected)
            <span @class(['absolute inset-y-0 start-0 w-1', 'highlight-bar-'.$item->highlight()])></span>
        @endif
        @if ($selected)
            <span class="absolute inset-y-0 start-0 w-0.5 bg-emerald-600 dark:bg-emerald-400"></span>
        @endif

        <span class="mt-2 flex w-2 shrink-0 justify-center">
            @if (! $item->isRead())
                <span class="size-2 rounded-full bg-emerald-600 dark:bg-emerald-400" title="{{ __('Unread') }}"></span>
                <span class="sr-only">{{ __('Unread') }}</span>
            @endif
        </span>

        <span class="min-w-0 flex-1">
            <span class="flex items-center gap-1.5 text-[0.7rem] font-medium tracking-wide text-zinc-500 uppercase dark:text-zinc-400">
                <span class="truncate">{{ $item->feed->displayTitle() }}</span>
                <span aria-hidden="true">&middot;</span>
                <time class="shrink-0 normal-case" datetime="{{ $item->published_at->toIso8601String() }}" title="{{ $item->published_at->isoFormat('LLLL') }}">
                    {{ $item->published_at->shortRelativeToNowDiffForHumans() }}
                </time>
                @if ($item->isStarred())
                    <flux:icon.star variant="micro" class="shrink-0 text-amber-500" />
                @endif
                @if ($item->isSavedForLater())
                    <flux:icon.bookmark variant="micro" class="shrink-0 text-emerald-600 dark:text-emerald-400" />
                @endif
            </span>

            <button type="button" @class([
                'mt-0.5 block text-start font-serif text-[1.05rem] leading-snug focus-visible:underline focus-visible:outline-none',
                'font-semibold text-zinc-900 dark:text-zinc-50' => ! $item->isRead(),
                'text-zinc-500 dark:text-zinc-400' => $item->isRead(),
            ])>{{ $item->title ?? __('Untitled') }}</button>

            @if ($layout === 'cards' && $item->summary)
                <span class="mt-1 line-clamp-2 block text-sm text-zinc-600 dark:text-zinc-400">{{ $item->summary }}</span>
            @endif

            <span class="mt-1.5 flex flex-wrap items-center gap-1 empty:hidden">
                @if ($top && $topSources > 1)
                    <flux:badge size="sm" color="emerald" icon="newspaper">{{ trans_choice(':count source|:count sources', $topSources) }}</flux:badge>
                @endif
                <x-reader.signals :signals="$top ? array_merge($signals ?? [], ['sources' => 1]) : $signals" />
                <x-reader.tag-chips :tags="$tags" />
            </span>
        </span>

        @if ($layout === 'cards' && $item->image_url)
            <img src="{{ $item->image_url }}" alt="" loading="lazy" referrerpolicy="no-referrer"
                class="mt-1 size-20 shrink-0 rounded-md bg-zinc-100 object-cover dark:bg-zinc-800"
                onerror="this.remove()">
        @endif
    </div>
@endif
</div>
