<?php

use App\Actions\Reader\TagEntries;
use App\Models\Entry;
use App\Models\SavedSearch;
use App\Services\Reader\EntryQuery;
use App\Services\Reader\EntryStates;
use App\Services\Reader\ReaderSource;
use App\Services\Reader\TopStories;
use App\Services\Teams\FeedAccess;
use App\Services\Watchlist\WatchlistMatcher;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::reader')] #[Title('Reader')] class extends Component {
    #[Url(as: 's', except: 'all')]
    public string $source = 'all';

    #[Url(except: 'unread')]
    public string $show = 'unread';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** Time filter: '' (any time), 'day', 'week' or 'month'. */
    #[Url(except: '')]
    public string $period = '';

    #[Url(as: 'e', except: null)]
    public ?int $entryId = null;

    #[Session]
    public string $layout = 'list';

    /** 'newest' or 'oldest' first. Kept for the session, like the layout. */
    #[Session]
    public string $sort = 'newest';

    public int $limit = 0;

    /** Highest entry id when the list was last loaded; anything above it is "new". */
    #[Locked]
    public int $newestId = 0;

    /** Entries read during this visit stay in the unread list until you leave. */
    public array $keep = [];

    /** River: entries whose full text is opened inline. */
    public array $expanded = [];

    public function mount(): void
    {
        $this->limit = config('newsstand.reader_page_size');
        $this->source = $this->parsedSource()->key();
        $this->newestId = (int) Entry::max('id');

        if ($this->entryId && ! $this->entry) {
            $this->entryId = null;
        }
    }

    // --- Data ---

    #[Computed]
    public function heading(): string
    {
        $source = $this->parsedSource();

        return match ($source->type) {
            'starred' => __('Starred'),
            'later' => __('Read later'),
            'feed' => FeedAccess::subscriptions(Auth::user())->with('feed')->where('feed_id', $source->id)->orderByRaw('user_id is null')->first()?->displayTitle() ?? __('Feed'),
            'folder' => FeedAccess::folders(Auth::user())->find($source->id)?->name ?? __('Folder'),
            'tag' => '#'.(FeedAccess::tags(Auth::user())->find($source->id)?->name ?? __('Tag')),
            'top' => __('Top stories'),
            'watchlist' => __('Your watchlist'),
            default => __('All stories'),
        };
    }

    /** The user's own tags, then their teams' shared tags, for the tag picker. */
    #[Computed]
    public function userTags(): Collection
    {
        return FeedAccess::tags(Auth::user())->with('team:id,name')
            ->orderByRaw('tags.team_id is not null')->orderBy('tags.name')->get();
    }

    /** Tags on the listed entries and the open entry: entry_id => tags. */
    #[Computed]
    public function entryTags(): Collection
    {
        $ids = $this->entries->pluck('id')->push($this->entryId)->filter()->unique()->values()->all();

        return app(EntryQuery::class)->tagsFor(Auth::user(), $ids);
    }

    public function isSearching(): bool
    {
        return trim($this->search) !== '';
    }

    #[Computed]
    public function entries(): Collection
    {
        if ($this->isTop()) {
            return app(TopStories::class)->for(Auth::user());
        }

        return $this->query()->with('feed:id,title,url,site_url')->limit($this->limit + 1)->get();
    }

    #[Computed]
    public function hasMore(): bool
    {
        return ! $this->isTop() && $this->entries->count() > $this->limit;
    }

    /** CVEs and source counts for the listed entries and the open entry. */
    #[Computed]
    public function signals(): array
    {
        $ids = $this->entries->pluck('id')->push($this->entryId)->filter()->unique()->values()->all();

        $signals = app(EntryQuery::class)->signalsFor($ids);
        $watch = app(WatchlistMatcher::class)->labelsFor(Auth::user(), $ids);

        foreach ($watch as $entryId => $labels) {
            $signals[$entryId]['watch'] = $labels;
        }

        return $signals;
    }

    public function isTop(): bool
    {
        return $this->parsedSource()->type === 'top';
    }

    #[Computed]
    public function entry(): ?Entry
    {
        return $this->entryId ? app(EntryQuery::class)->find(Auth::user(), $this->entryId) : null;
    }

    // --- Actions ---

    public function select(int $id): void
    {
        $entry = app(EntryQuery::class)->find(Auth::user(), $id);

        if (! $entry) {
            return;
        }

        $this->entryId = $entry->id;
        $this->keepVisible([$entry->id]);

        if (! $entry->isRead()) {
            app(EntryStates::class)->markRead(Auth::user(), [$entry->id]);
            $this->dispatch('unread-changed');
        }

        unset($this->entry, $this->entries);
    }

    public function closeEntry(): void
    {
        $this->entryId = null;
    }

    public function toggleRead(?int $id = null): void
    {
        if (! $entry = $this->findEntry($id)) {
            return;
        }

        $this->keepVisible([$entry->id]);
        app(EntryStates::class)->markRead(Auth::user(), [$entry->id], ! $entry->isRead());

        $this->dispatch('unread-changed');
        unset($this->entry, $this->entries);
    }

    public function toggleStar(?int $id = null): void
    {
        if (! $entry = $this->findEntry($id)) {
            return;
        }

        $starred = app(EntryStates::class)->toggleStar(Auth::user(), $entry);

        $this->dispatch('unread-changed');
        unset($this->entry, $this->entries);
        Flux::toast(text: $starred ? __('Starred.') : __('Removed from starred.'), duration: 1500);
    }

    public function toggleLater(?int $id = null): void
    {
        if (! $entry = $this->findEntry($id)) {
            return;
        }

        $later = app(EntryStates::class)->toggleLater(Auth::user(), $entry);

        $this->dispatch('unread-changed');
        unset($this->entry, $this->entries);
        Flux::toast(text: $later ? __('Saved for later.') : __('Removed from read later.'), duration: 1500);
    }

    public function toggleTag(int $tagId, ?int $id = null): void
    {
        if (! $entry = $this->findEntry($id)) {
            return;
        }

        app(TagEntries::class)->toggle(Auth::user(), $entry, $tagId);

        $this->dispatch('tags-changed');
        unset($this->entryTags);
    }

    public function addTag(string $name, ?int $id = null): void
    {
        if (! $entry = $this->findEntry($id)) {
            return;
        }

        try {
            $tag = app(TagEntries::class)->createAndAttach(Auth::user(), $entry, $name);
        } catch (\InvalidArgumentException) {
            return;
        }

        $this->dispatch('tags-changed');
        unset($this->entryTags, $this->userTags);
        Flux::toast(text: __('Tagged #:tag.', ['tag' => $tag->name]), duration: 1500);
    }

    /** Mark everything here read, or only what is older than a day or a week ($olderThan 'day' / 'week'). */
    public function markAllRead(?string $olderThan = null): void
    {
        $query = app(EntryQuery::class)->for(Auth::user(), $this->parsedSource(), false, $this->search, [], $this->period ?: null);

        if (isset(EntryQuery::PERIODS[$olderThan])) {
            $query->where('entries.published_at', '<', now()->subHours(EntryQuery::PERIODS[$olderThan]));
        }

        $count = app(EntryStates::class)->markAllRead(Auth::user(), $query);

        $this->keep = [];
        $this->dispatch('unread-changed');
        unset($this->entries, $this->entry);

        Flux::toast(variant: 'success', text: trans_choice('{0} Nothing to mark.|{1} Marked 1 story as read.|[2,*] Marked :count stories as read.', $count));
    }

    public function toggleSort(): void
    {
        $this->sort = $this->sort === 'oldest' ? 'newest' : 'oldest';
        $this->limit = config('newsstand.reader_page_size');
        $this->keep = [];
    }

    /** The saved search matching the current source and search, if any. */
    #[Computed]
    public function savedSearch(): ?SavedSearch
    {
        return $this->isSearching()
            ? Auth::user()->savedSearches()->where('source', $this->parsedSource()->key())->where('query', trim($this->search))->first()
            : null;
    }

    /** Keep the current search in the sidebar, or take it out again. */
    public function toggleSavedSearch(): void
    {
        if (! $this->isSearching()) {
            return;
        }

        if ($saved = $this->savedSearch) {
            $saved->delete();
            Flux::toast(text: __('Removed from saved searches.'), duration: 1500);
        } else {
            $query = mb_substr(trim($this->search), 0, 250);
            Auth::user()->savedSearches()->create([
                'name' => mb_substr($query, 0, 100),
                'source' => $this->parsedSource()->key(),
                'query' => $query,
            ]);
            Flux::toast(text: __('Saved. It is listed in the sidebar.'), duration: 2000);
        }

        unset($this->savedSearch);
        $this->dispatch('saved-searches-changed');
    }

    public function loadMore(): void
    {
        if ($this->hasMore) {
            $this->limit += config('newsstand.reader_page_size');
        }
    }

    public function setLayout(string $layout): void
    {
        $this->layout = in_array($layout, ['list', 'cards', 'river'], true) ? $layout : 'list';

        if ($this->layout === 'river') {
            $this->entryId = null;
        }
    }

    /** River: open or close the full text of one story. Opening it counts as reading it. */
    public function expand(int $id): void
    {
        if (in_array($id, $this->expanded, true)) {
            $this->expanded = array_values(array_diff($this->expanded, [$id]));

            return;
        }

        $entry = app(EntryQuery::class)->find(Auth::user(), $id);

        if (! $entry) {
            return;
        }

        $this->expanded[] = $entry->id;
        $this->keepVisible([$entry->id]);

        if (! $entry->isRead()) {
            app(EntryStates::class)->markRead(Auth::user(), [$entry->id]);
            $this->dispatch('unread-changed');
        }

        unset($this->entries);
    }

    /**
     * River view: entries the user scrolled past. Only marks entries that are
     * visible in the current view and still unread. Renderless: the client
     * already dimmed them, so we don't re-render a column of full articles.
     *
     * @param  list<int>  $ids
     */
    #[Renderless]
    public function markSeen(array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', array_slice($ids, 0, 200))));

        if ($ids === []) {
            return;
        }

        $unread = app(EntryQuery::class)
            ->for(Auth::user(), $this->parsedSource())
            ->whereIn('entries.id', $ids)
            ->whereNull('eu.read_at')
            ->reorder()
            ->pluck('entries.id')
            ->all();

        if ($unread === []) {
            return;
        }

        app(EntryStates::class)->markRead(Auth::user(), $unread);
        $this->keepVisible($unread);

        $this->dispatch('unread-changed');
    }

    /**
     * Polled by the page: how many stories arrived in this view since it was loaded.
     * Renderless, so polling never re-renders (or reshuffles) the list.
     */
    #[Renderless]
    public function countNew(): int
    {
        if ($this->isTop()) {
            return 0;
        }

        return $this->query()
            ->where('entries.id', '>', $this->newestId)
            ->reorder()
            ->limit(100)
            ->pluck('entries.id')
            ->count();
    }

    /** Pull the new stories into the list. */
    public function showNew(): void
    {
        $this->newestId = (int) Entry::max('id');
        unset($this->entries, $this->signals, $this->entryTags);
        $this->dispatch('unread-changed');
    }

    public function updated(string $property): void
    {
        if ($property === 'search') {
            $this->dispatch('reader-search-changed', search: $this->search);
        }

        if (in_array($property, ['show', 'search', 'period'], true)) {
            $this->limit = config('newsstand.reader_page_size');
            $this->keep = [];
            $this->newestId = (int) Entry::max('id');
        }
    }

    // --- Helpers ---

    /**
     * A row's key covers everything the row shows, so a re-render only redraws rows whose
     * state changed (read, starred, tags, selection, ...); unchanged rows are skipped.
     */
    public function rowKey(Entry $item): string
    {
        $tags = collect($this->entryTags[$item->id] ?? [])->map(fn ($t) => $t->id.':'.$t->name.':'.$t->color)->implode(',');
        $signals = $this->signals[$item->id] ?? [];

        return 'row-'.$item->id.'-'.md5(json_encode([
            $this->layout, $item->id === $this->entryId, in_array($item->id, $this->expanded, true), $this->isTop(),
            $item->isRead(), $item->isStarred(), $item->isSavedForLater(), $item->highlight(), $item->top_sources ?? null,
            (string) $item->updated_at, $tags,
            collect($signals['cves'] ?? [])->map(fn ($c) => $c->cve_id.$c->cvss_score.(int) $c->in_kev)->implode(','),
            $signals['sources'] ?? 1, collect($signals['watch'] ?? [])->implode(','),
            // River rows hold the tag menu, which lists all of the user's tags.
            $this->layout === 'river' ? $this->userTags->map(fn ($t) => $t->id.':'.$t->name)->implode(',') : '',
        ]));
    }

    /**
     * Read stories that stay listed until you leave. Capped: the list travels with
     * every request and ends up in the query, and a long river session would grow it
     * without limit (the oldest ones are far out of view by then).
     *
     * @param  list<int>  $ids
     */
    private function keepVisible(array $ids): void
    {
        $this->keep = array_slice(array_values(array_unique([...$this->keep, ...$ids])), -500);
    }

    /** Searching always looks through read stories too. */
    private function unreadOnly(): bool
    {
        return $this->show !== 'all' && ! $this->isSearching();
    }

    private function parsedSource(): ReaderSource
    {
        $source = ReaderSource::parse($this->source);

        $owned = match ($source->type) {
            'feed' => FeedAccess::canRead(Auth::user(), $source->id),
            'folder' => FeedAccess::folders(Auth::user())->whereKey($source->id)->exists(),
            'tag' => FeedAccess::tags(Auth::user())->whereKey($source->id)->exists(),
            default => true,
        };

        return $owned ? $source : ReaderSource::all();
    }

    /** @return Builder<Entry> */
    private function query(): Builder
    {
        return app(EntryQuery::class)->for(Auth::user(), $this->parsedSource(), $this->unreadOnly(), $this->search, $this->keep, $this->period ?: null, $this->sort === 'oldest');
    }

    private function findEntry(?int $id): ?Entry
    {
        $id ??= $this->entryId;

        return $id ? app(EntryQuery::class)->find(Auth::user(), $id) : null;
    }
}; ?>

<div class="flex h-[calc(100dvh-3.5rem)] lg:h-dvh" x-data="reader" data-layout="{{ $layout }}" @keydown.window="onKey($event)">
    {{-- Entry list (or the river, full width) --}}
    <section @class([
        'flex w-full min-w-0 flex-col border-zinc-200 dark:border-zinc-700',
        'lg:w-[26rem] lg:shrink-0 lg:border-e xl:w-[30rem]' => $layout !== 'river',
        'hidden lg:flex' => $entryId && $layout !== 'river',
    ])>
        <header class="space-y-3 border-b border-zinc-200 px-4 pt-4 pb-3 dark:border-zinc-700">
            <div class="flex items-center justify-between gap-2">
                <flux:heading size="xl" class="truncate">{{ $this->heading }}</flux:heading>

                <div class="flex shrink-0 items-center gap-1">
                    {{-- A dropdown trigger gets a plain title, never a Flux tooltip (popover clash on close). --}}
                    <flux:dropdown position="bottom" align="end">
                        <flux:button size="sm" variant="ghost" icon="check-circle" data-test="mark-read-menu"
                            :aria-label="__('Mark as read')" :title="__('Mark as read') . ' (Shift+A: all)'" />
                        <flux:menu>
                            <flux:menu.item icon="check-circle" wire:click="markAllRead"
                                wire:confirm="{{ __('Mark every story here as read?') }}" data-test="mark-all-read">{{ __('Mark all as read') }}</flux:menu.item>
                            <flux:menu.item icon="clock" wire:click="markAllRead('day')" data-test="mark-older-day">{{ __('Older than 24 hours') }}</flux:menu.item>
                            <flux:menu.item icon="calendar" wire:click="markAllRead('week')" data-test="mark-older-week">{{ __('Older than 7 days') }}</flux:menu.item>
                        </flux:menu>
                    </flux:dropdown>
                    @unless ($this->isTop())
                        <flux:button size="sm" variant="ghost" :icon="$sort === 'oldest' ? 'bars-arrow-up' : 'bars-arrow-down'" wire:click="toggleSort"
                            data-test="sort-toggle" aria-pressed="{{ $sort === 'oldest' ? 'true' : 'false' }}"
                            :aria-label="__('Oldest first')" :tooltip="$sort === 'oldest' ? __('Oldest first (click for newest first)') : __('Newest first (click for oldest first)')" />
                    @endunless
                    <div class="flex items-center rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800">
                        @foreach (['list' => ['bars-3', __('List')], 'cards' => ['squares-2x2', __('Cards')], 'river' => ['newspaper', __('River: scroll through stories')]] as $mode => [$icon, $label])
                            <flux:tooltip :content="$label">
                                <flux:button size="xs" :variant="$layout === $mode ? 'filled' : 'subtle'" :icon="$icon"
                                    wire:click="setLayout('{{ $mode }}')" data-test="layout-{{ $mode }}"
                                    :aria-label="$label" aria-pressed="{{ $layout === $mode ? 'true' : 'false' }}" />
                            </flux:tooltip>
                        @endforeach
                    </div>
                    <flux:tooltip :content="__('Keyboard shortcuts') . ' (?)'">
                        <flux:modal.trigger name="shortcuts">
                            <flux:button size="sm" variant="ghost" icon="command-line" :aria-label="__('Keyboard shortcuts')" />
                        </flux:modal.trigger>
                    </flux:tooltip>
                </div>
            </div>

            @if ($this->isTop())
                <flux:text size="sm">{{ __('The most covered and most severe stories of the last 48 hours, one per story.') }}</flux:text>
            @endif

            <div @class(['flex flex-wrap items-center gap-2', 'hidden' => $this->isTop()])>
                <flux:input wire:model.live.debounce.400ms="search" x-ref="search" size="sm" icon="magnifying-glass"
                    :placeholder="__('Search')" class="min-w-40 flex-1" clearable kbd="/" />

                <flux:dropdown position="bottom" align="end">
                    <flux:button size="sm" :variant="$period ? 'filled' : 'ghost'" icon="calendar" data-test="period-filter"
                        :aria-label="__('Time period')" :title="__('Time period')">
                        <span class="max-sm:hidden">{{ ['' => __('Any time'), 'day' => __('24 h'), 'week' => __('7 days'), 'month' => __('30 days')][$period] ?? __('Any time') }}</span>
                    </flux:button>
                    <flux:menu>
                        <flux:menu.radio.group wire:model.live="period">
                            <flux:menu.radio value="">{{ __('Any time') }}</flux:menu.radio>
                            <flux:menu.radio value="day">{{ __('Last 24 hours') }}</flux:menu.radio>
                            <flux:menu.radio value="week">{{ __('Last 7 days') }}</flux:menu.radio>
                            <flux:menu.radio value="month">{{ __('Last 30 days') }}</flux:menu.radio>
                        </flux:menu.radio.group>
                    </flux:menu>
                </flux:dropdown>

                <flux:radio.group wire:model.live="show" variant="segmented" size="sm">
                    <flux:radio value="unread" :label="__('Unread')" :disabled="$this->isSearching()" />
                    <flux:radio value="all" :label="__('All')" :disabled="$this->isSearching()" />
                </flux:radio.group>
            </div>

            @if ($this->isSearching())
                <flux:text size="sm" class="flex flex-wrap items-center gap-1.5">
                    <flux:icon.magnifying-glass variant="micro" />
                    {{ __('Searching all stories, read and unread.') }}
                    <flux:button size="xs" variant="ghost" :icon="$this->savedSearch ? 'bookmark-slash' : 'bookmark-square'" wire:click="toggleSavedSearch" data-test="save-search">
                        {{ $this->savedSearch ? __('Remove saved search') : __('Save search') }}
                    </flux:button>
                    @if (\App\Services\Reader\EntryQuery::usesFullTextIndex())
                        <flux:tooltip :content="__('Use “quotes” for phrases, -word to exclude, OR for alternatives.')">
                            <flux:icon.information-circle variant="micro" class="cursor-help" />
                        </flux:tooltip>
                    @endif
                </flux:text>
            @endif
        </header>

        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain transition-opacity" x-ref="list" data-scroll-area x-on:scroll.passive="riverScroll()"
            wire:loading.class="opacity-50" wire:target="search, show, period, setLayout, markAllRead, showNew" wire:loading.attr="aria-busy">
            {{-- Read through $data so a re-run after teardown finds "undefined", not a ReferenceError. --}}
            <div x-show="($data.newCount ?? 0) > 0" x-cloak wire:ignore role="status" aria-live="polite" class="sticky top-0 z-10 flex justify-center px-4 py-2">
                <flux:button size="sm" variant="primary" icon="arrow-up" x-on:click="showNew()" data-test="show-new">
                    <span x-text="(n => n >= 100 ? @js(__('99+ new stories')) : (n === 1 ? @js(__('1 new story')) : @js(__(':count new stories')).replace(':count', n)))($data.newCount ?? 0)"></span>
                </flux:button>
            </div>
            {{-- Each story is its own component, keyed on what it shows: when the reader re-renders,
                 unchanged rows are skipped and only changed ones are drawn again. --}}
            @forelse ($this->entries->take($this->limit) as $item)
                <livewire:reader.row :key="$this->rowKey($item)" :entry="$item" :layout="$layout"
                    :selected="$layout !== 'river' && $item->id === $entryId" :expanded="in_array($item->id, $expanded, true)" :top="$this->isTop()"
                    :signals="$this->signals[$item->id] ?? null" :tags="$this->entryTags[$item->id] ?? []"
                    :user-tags="$layout === 'river' ? $this->userTags : null" />
            @empty
                <div class="flex flex-col items-center px-6 py-16 text-center">
                    <x-app-mark size="lg" class="opacity-60" />
                    <flux:heading size="lg" class="mt-4">
                        {{ $search !== '' ? __('No stories match your search') : ($show === 'unread' ? __('All caught up') : __('Nothing here yet')) }}
                    </flux:heading>
                    <flux:text class="mt-1">
                        @if ($show === 'unread' && $search === '')
                            {{ __('No unread stories.') }}
                            <flux:link class="cursor-pointer" wire:click="$set('show', 'all')">{{ __('Show all') }}</flux:link>
                        @elseif (! FeedAccess::subscriptions(Auth::user())->exists())
                            <flux:link :href="route('feeds.index')" wire:navigate>{{ __('Subscribe to your first feed') }}</flux:link>
                        @endif
                    </flux:text>
                </div>
            @endforelse

            @if ($this->hasMore)
                <div x-intersect.margin.400px="$wire.loadMore()" class="flex justify-center py-6">
                    <flux:button size="sm" variant="ghost" wire:click="loadMore">{{ __('Load more') }}</flux:button>
                </div>
            @elseif ($layout === 'river' && $this->entries->isNotEmpty())
                {{-- Room to scroll the last articles past the top, so they get marked read too. --}}
                <div class="flex min-h-[70vh] flex-col items-center px-6 pt-16 text-center">
                    <x-app-mark size="lg" class="opacity-50" />
                    <p class="mt-4 font-serif text-xl text-zinc-600 italic dark:text-zinc-400">{{ __("That's everything for now.") }}</p>
                </div>
            @endif
        </div>
    </section>

    {{-- Reading pane (not used by the river) --}}
    @if ($layout !== 'river')
    <section @class(['min-w-0 flex-1 flex-col', 'flex' => $entryId, 'hidden lg:flex' => ! $entryId])>
        @if ($entry = $this->entry)
            <div class="flex items-center gap-1 border-b border-zinc-200 px-3 py-2 dark:border-zinc-700">
                <flux:button size="sm" variant="ghost" icon="arrow-left" wire:click="closeEntry" class="lg:hidden">{{ __('Back') }}</flux:button>
                <flux:button size="sm" variant="ghost" icon="chevron-up" x-on:click="move(-1)" class="max-lg:hidden" :tooltip="__('Previous') . ' (k)'" />
                <flux:button size="sm" variant="ghost" icon="chevron-down" x-on:click="move(1)" class="max-lg:hidden" :tooltip="__('Next') . ' (j)'" />

                <flux:spacer />

                <x-reader.tag-menu :entry-id="$entry->id" :tags="$this->userTags" :selected="$this->entryTags[$entry->id] ?? []" />
                <flux:button size="sm" variant="ghost" wire:click="toggleStar" data-test="toggle-star"
                    :icon="$entry->isStarred() ? 'star' : 'star'" :icon:variant="$entry->isStarred() ? 'solid' : 'outline'"
                    @class(['!text-amber-500' => $entry->isStarred()]) :tooltip="__('Star') . ' (s)'" />
                <flux:button size="sm" variant="ghost" wire:click="toggleLater"
                    icon="bookmark" :icon:variant="$entry->isSavedForLater() ? 'solid' : 'outline'"
                    @class(['!text-emerald-600 dark:!text-emerald-400' => $entry->isSavedForLater()]) :tooltip="__('Read later') . ' (l)'" />
                <flux:button size="sm" variant="ghost" wire:click="toggleRead"
                    :icon="$entry->isRead() ? 'envelope' : 'envelope-open'" :tooltip="($entry->isRead() ? __('Mark unread') : __('Mark read')) . ' (m)'" />
                @if ($entry->url)
                    <flux:button size="sm" variant="ghost" icon="arrow-top-right-on-square" :href="$entry->url" target="_blank" rel="noopener noreferrer"
                        :tooltip="__('Open original') . ' (v)'" />
                @endif
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain" x-ref="pane">
                <x-reader.article :entry="$entry" wire:key="article-{{ $entry->id }}" :signals="$this->signals[$entry->id] ?? null">
                    <x-slot:badges>
                        <x-reader.tag-chips :tags="$this->entryTags[$entry->id] ?? []" />
                    </x-slot:badges>
                </x-reader.article>
            </div>
        @else
            <div class="flex flex-1 flex-col items-center justify-center p-10 text-center">
                <x-app-mark size="xl" class="opacity-40" />
                <p class="mt-6 font-serif text-2xl text-zinc-700 italic dark:text-zinc-300">{{ __('Pick a story to start reading.') }}</p>
                <flux:text class="mt-2">
                    {{ __('Press') }} <flux:badge size="sm">j</flux:badge> {{ __('to open the first one, or') }} <flux:badge size="sm">?</flux:badge> {{ __('for all shortcuts.') }}
                </flux:text>
            </div>
        @endif
    </section>
    @endif

    {{-- Shortcuts --}}
    <flux:modal name="shortcuts" class="md:w-[28rem]">
        <flux:heading size="lg">{{ __('Keyboard shortcuts') }}</flux:heading>
        <dl class="mt-5 grid grid-cols-[auto_1fr] items-center gap-x-6 gap-y-2.5 text-sm">
            @foreach ([
                ['j / k', __('Next / previous story (in the river: jump between articles)')],
                ['o', __('Open the first story')],
                ['v', __('Open original in a new tab')],
                ['s', __('Star / unstar')],
                ['l', __('Save for later')],
                ['m', __('Mark read / unread')],
                ['t', __('Tag the story')],
                ['Shift + A', __('Mark all as read')],
                ['u', __('Toggle unread / all')],
                ['/', __('Search')],
                ['Esc', __('Close story')],
                ['?', __('Show this help')],
            ] as [$keys, $label])
                <dt><flux:badge size="sm" class="font-mono">{{ $keys }}</flux:badge></dt>
                <dd class="text-zinc-600 dark:text-zinc-300">{{ $label }}</dd>
            @endforeach
        </dl>
    </flux:modal>
</div>
