<?php

use App\Models\Dashboard;
use App\Models\DashboardWidget;
use App\Models\Entry;
use App\Models\User;
use App\Services\Dashboards\WidgetContent;
use App\Services\Reader\EntryQuery;
use App\Services\Reader\EntryStates;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    #[Locked]
    public int $dashboardId;

    public bool $editing = false;

    // Widget form
    public ?int $widgetId = null;
    public string $wType = 'feed';
    public string $wTitle = '';
    public string $wFeedId = '';
    public string $wFolderId = '';
    public string $wTagId = '';
    public string $wQuery = '';
    public string $wFilter = 'kev';
    public int $wLimit = 8;
    public bool $wWide = false;

    // Sharing
    public string $shareEmail = '';
    public string $shareRole = 'viewer';

    // Rename
    public string $dashboardName = '';

    public ?int $openEntryId = null;

    /** Hide stories this viewer has read. Remembered per dashboard in the session. */
    public bool $unreadOnly = false;

    /** Stories opened during this visit stay visible in unread-only mode until you leave. */
    public array $keep = [];

    public function mount(Dashboard $dashboard): void
    {
        $this->authorize('view', $dashboard);

        $this->dashboardId = $dashboard->id;
        $this->dashboardName = $dashboard->name;
        $this->unreadOnly = (bool) session("dashboards.{$dashboard->id}.unread", false);
    }

    public function updatedUnreadOnly(bool $value): void
    {
        session(["dashboards.{$this->dashboardId}.unread" => $value]);
        $this->keep = [];
        unset($this->contents);
    }

    // --- Data ---

    #[Computed]
    public function dashboard(): Dashboard
    {
        return Dashboard::with(['owner', 'widgets'])->findOrFail($this->dashboardId);
    }

    #[Computed]
    public function role(): ?string
    {
        return $this->dashboard->roleOf(Auth::user());
    }

    public function canEdit(): bool
    {
        return in_array($this->role, ['owner', 'editor'], true);
    }

    /** widget id => entries or CVEs */
    #[Computed]
    public function contents(): array
    {
        $content = app(WidgetContent::class);

        return $this->dashboard->widgets
            ->mapWithKeys(fn (DashboardWidget $w) => [$w->id => $content->for($w, Auth::user(), $this->unreadOnly, $this->keep)])
            ->all();
    }

    #[Computed]
    public function signals(): array
    {
        $ids = collect($this->contents)->flatten(1)->filter(fn ($item) => $item instanceof Entry)->pluck('id')->unique()->values()->all();

        return app(EntryQuery::class)->signalsFor($ids);
    }

    /** Widget options come from the owner's feeds, folders and tags (content is the owner's). */
    #[Computed]
    public function options(): array
    {
        $owner = $this->dashboard->owner;

        return [
            'feeds' => $owner->subscriptions()->with('feed:id,title,url')->get()
                ->map(fn ($s) => ['id' => $s->feed_id, 'title' => $s->displayTitle()])->sortBy('title', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            'folders' => $owner->folders()->get(['id', 'name']),
            'tags' => $owner->tags()->get(['id', 'name']),
        ];
    }

    #[Computed]
    public function members(): Collection
    {
        return $this->dashboard->members()->orderBy('name')->get();
    }

    #[Computed]
    public function openEntry(): ?Entry
    {
        return $this->openEntryId ? Entry::with('feed')->find($this->openEntryId) : null;
    }

    public function widgetTitle(DashboardWidget $widget): string
    {
        if ($widget->title) {
            return $widget->title;
        }

        $c = $widget->config;
        $o = $this->options;

        return match ($widget->type) {
            'feed' => $o['feeds']->firstWhere('id', $c['feed_id'] ?? null)['title'] ?? __('Feed'),
            'folder' => $o['folders']->firstWhere('id', $c['folder_id'] ?? null)?->name ?? __('Folder'),
            'tag' => '#'.($o['tags']->firstWhere('id', $c['tag_id'] ?? null)?->name ?? __('tag')),
            'search' => __('Search: :q', ['q' => $c['query'] ?? '']),
            'starred' => __('Starred by :name', ['name' => $this->dashboard->owner->name]),
            'top' => __('Top stories'),
            'cves' => match ($c['filter'] ?? 'kev') {
                'critical' => __('Critical vulnerabilities'),
                'mentioned' => __('Vulnerabilities in the news'),
                default => __('Actively exploited'),
            },
            default => $widget->type,
        };
    }

    // --- Reading ---

    public function open(int $entryId): void
    {
        if (! app(WidgetContent::class)->canShow($this->dashboard, $entryId)) {
            return;
        }

        $this->openEntryId = $entryId;
        $this->keep[] = $entryId;
        app(EntryStates::class)->markRead(Auth::user(), [$entryId]);
        $this->dispatch('unread-changed');
        unset($this->contents, $this->openEntry);

        Flux::modal('story')->show();
    }

    // --- Editing widgets ---

    public function toggleEditing(): void
    {
        $this->authorize('update', $this->dashboard);
        $this->editing = ! $this->editing;
    }

    public function newWidget(): void
    {
        $this->authorize('update', $this->dashboard);
        $this->resetWidgetForm();

        Flux::modal('widget')->show();
    }

    public function editWidget(int $id): void
    {
        $this->authorize('update', $this->dashboard);
        $widget = $this->findWidget($id);
        $c = $widget->config;

        $this->resetWidgetForm();
        $this->widgetId = $widget->id;
        $this->wType = $widget->type;
        $this->wTitle = (string) $widget->title;
        $this->wFeedId = (string) ($c['feed_id'] ?? '');
        $this->wFolderId = (string) ($c['folder_id'] ?? '');
        $this->wTagId = (string) ($c['tag_id'] ?? '');
        $this->wQuery = (string) ($c['query'] ?? '');
        $this->wFilter = (string) ($c['filter'] ?? 'kev');
        $this->wLimit = $widget->limit();
        $this->wWide = $widget->wide;

        Flux::modal('widget')->show();
    }

    public function saveWidget(): void
    {
        $this->authorize('update', $this->dashboard);
        $o = $this->options;

        $this->validate([
            'wType' => ['required', Rule::in(DashboardWidget::TYPES)],
            'wTitle' => ['nullable', 'string', 'max:100'],
            'wLimit' => ['integer', 'min:3', 'max:20'],
            'wFeedId' => [Rule::requiredIf($this->wType === 'feed'), 'nullable', Rule::in($o['feeds']->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'wFolderId' => [Rule::requiredIf($this->wType === 'folder'), 'nullable', Rule::in($o['folders']->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'wTagId' => [Rule::requiredIf($this->wType === 'tag'), 'nullable', Rule::in($o['tags']->pluck('id')->map(fn ($id) => (string) $id)->all())],
            'wQuery' => [Rule::requiredIf($this->wType === 'search'), 'nullable', 'string', 'max:200'],
            'wFilter' => ['in:kev,critical,mentioned'],
        ], [], [
            'wFeedId' => __('feed'), 'wFolderId' => __('folder'), 'wTagId' => __('tag'), 'wQuery' => __('search'),
        ]);

        $config = array_filter([
            'limit' => $this->wLimit,
            'feed_id' => $this->wType === 'feed' ? (int) $this->wFeedId : null,
            'folder_id' => $this->wType === 'folder' ? (int) $this->wFolderId : null,
            'tag_id' => $this->wType === 'tag' ? (int) $this->wTagId : null,
            'query' => $this->wType === 'search' ? trim($this->wQuery) : null,
            'filter' => $this->wType === 'cves' ? $this->wFilter : null,
        ], fn ($v) => $v !== null);

        $data = ['type' => $this->wType, 'title' => $this->wTitle ?: null, 'config' => $config, 'wide' => $this->wWide];

        if ($this->widgetId) {
            $this->findWidget($this->widgetId)->update($data);
        } else {
            $this->dashboard->widgets()->create([...$data, 'position' => (int) $this->dashboard->widgets()->max('position') + 1]);
        }

        Flux::modal('widget')->close();
        $this->editing = true;
        unset($this->dashboard, $this->contents);
    }

    public function removeWidget(int $id): void
    {
        $this->authorize('update', $this->dashboard);
        $this->findWidget($id)->delete();
        unset($this->dashboard, $this->contents);
    }

    public function sortWidget(int $id, int $position): void
    {
        $this->authorize('update', $this->dashboard);

        $ids = $this->dashboard->widgets->pluck('id')->reject(fn ($other) => $other === $id)->values()->all();

        if (! $this->dashboard->widgets->contains('id', $id)) {
            return;
        }

        array_splice($ids, max(0, min($position, count($ids))), 0, [$id]);

        foreach ($ids as $index => $widgetId) {
            DashboardWidget::whereKey($widgetId)->update(['position' => $index]);
        }

        unset($this->dashboard, $this->contents);
    }

    // --- Sharing & settings (owner only) ---

    public function share(): void
    {
        $this->authorize('manage', $this->dashboard);
        $this->validate(['shareEmail' => ['required', 'email'], 'shareRole' => ['in:viewer,editor']]);

        $user = User::where('email', Str::lower(trim($this->shareEmail)))->first();

        if (! $user) {
            $this->addError('shareEmail', __('There is no Newsstand user with that email address.'));

            return;
        }

        if ($user->id === $this->dashboard->owner_id) {
            $this->addError('shareEmail', __('You already own this dashboard.'));

            return;
        }

        $this->dashboard->members()->syncWithoutDetaching([$user->id => ['role' => $this->shareRole]]);
        $this->dashboard->members()->updateExistingPivot($user->id, ['role' => $this->shareRole]);

        $this->reset('shareEmail');
        unset($this->members);
        Flux::toast(variant: 'success', text: __('Shared with :name.', ['name' => $user->name]));
    }

    public function changeRole(int $userId, string $role): void
    {
        $this->authorize('manage', $this->dashboard);

        if (in_array($role, Dashboard::ROLES, true)) {
            $this->dashboard->members()->updateExistingPivot($userId, ['role' => $role]);
            unset($this->members);
        }
    }

    public function removeMember(int $userId): void
    {
        $this->authorize('manage', $this->dashboard);
        $this->dashboard->members()->detach($userId);
        unset($this->members);
    }

    public function togglePublicLink(): void
    {
        $this->authorize('manage', $this->dashboard);

        $this->dashboard->forceFill(['share_token' => $this->dashboard->share_token ? null : Str::random(40)])->save();
        unset($this->dashboard);
    }

    public function regeneratePublicLink(): void
    {
        $this->authorize('manage', $this->dashboard);

        $this->dashboard->forceFill(['share_token' => Str::random(40)])->save();
        unset($this->dashboard);
        Flux::toast(text: __('New link created. The old link no longer works.'));
    }

    public function rename(): void
    {
        $this->authorize('manage', $this->dashboard);
        $this->validate(['dashboardName' => ['required', 'string', 'max:100']]);

        $this->dashboard->update(['name' => $this->dashboardName]);
        unset($this->dashboard);
        Flux::modal('rename')->close();
    }

    public function deleteDashboard(): void
    {
        $this->authorize('manage', $this->dashboard);
        $this->dashboard->delete();

        $this->redirectRoute('dashboards.index', navigate: true);
    }

    public function leave(): void
    {
        $this->dashboard->members()->detach(Auth::id());

        $this->redirectRoute('dashboards.index', navigate: true);
    }

    // --- Helpers ---

    private function findWidget(int $id): DashboardWidget
    {
        return $this->dashboard->widgets()->findOrFail($id);
    }

    private function resetWidgetForm(): void
    {
        $this->reset('widgetId', 'wType', 'wTitle', 'wFeedId', 'wFolderId', 'wTagId', 'wQuery', 'wFilter', 'wLimit', 'wWide');
        $this->resetErrorBag();
    }
}; ?>

<div class="mx-auto w-full max-w-7xl space-y-6">
    @php($dashboard = $this->dashboard)

    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <flux:breadcrumbs>
                <flux:breadcrumbs.item :href="route('dashboards.index')" wire:navigate>{{ __('Dashboards') }}</flux:breadcrumbs.item>
                <flux:breadcrumbs.item>{{ $dashboard->name }}</flux:breadcrumbs.item>
            </flux:breadcrumbs>
            <flux:heading size="xl" level="1" class="mt-2">{{ $dashboard->name }}</flux:heading>
            @if ($this->role !== 'owner')
                <flux:text class="mt-1">{{ __('Shared by :name', ['name' => $dashboard->owner->name]) }} · {{ $this->role === 'editor' ? __('you can edit') : __('view only') }}</flux:text>
            @endif
        </div>

        <div class="flex items-center gap-2">
            <div class="me-2 flex items-center rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800" data-test="unread-toggle">
                <flux:button size="sm" :variant="$unreadOnly ? 'subtle' : 'filled'" wire:click="$set('unreadOnly', false)">{{ __('All') }}</flux:button>
                <flux:button size="sm" :variant="$unreadOnly ? 'filled' : 'subtle'" wire:click="$set('unreadOnly', true)" data-test="unread-only">{{ __('Unread') }}</flux:button>
            </div>
            @if ($this->canEdit())
                @if ($editing)
                    <flux:button icon="plus" wire:click="newWidget" data-test="add-widget">{{ __('Add widget') }}</flux:button>
                @endif
                <flux:button :variant="$editing ? 'primary' : 'ghost'" :icon="$editing ? 'check' : 'pencil-square'" wire:click="toggleEditing" data-test="toggle-editing">
                    {{ $editing ? __('Done') : __('Edit') }}
                </flux:button>
            @endif
            @if ($this->role === 'owner')
                <flux:modal.trigger name="share">
                    <flux:button icon="share" data-test="share">{{ __('Share') }}</flux:button>
                </flux:modal.trigger>
                <flux:dropdown align="end">
                    <flux:button variant="ghost" icon="ellipsis-horizontal" />
                    <flux:menu>
                        <flux:modal.trigger name="rename"><flux:menu.item icon="pencil">{{ __('Rename') }}</flux:menu.item></flux:modal.trigger>
                        <flux:menu.item icon="trash" variant="danger" wire:click="deleteDashboard"
                            wire:confirm="{{ __('Delete this dashboard for everyone it is shared with?') }}">{{ __('Delete dashboard') }}</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            @else
                <flux:button variant="ghost" icon="arrow-right-start-on-rectangle" wire:click="leave"
                    wire:confirm="{{ __('Remove this dashboard from your list?') }}">{{ __('Leave') }}</flux:button>
            @endif
        </div>
    </div>

    {{-- Widgets --}}
    @if ($dashboard->widgets->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-12 text-center dark:border-zinc-700">
            <flux:icon.squares-plus class="mx-auto size-8 text-zinc-400" />
            <flux:heading class="mt-3">{{ __('This dashboard is empty') }}</flux:heading>
            @if ($this->canEdit())
                <flux:button class="mt-4" icon="plus" wire:click="newWidget">{{ __('Add a widget') }}</flux:button>
            @endif
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" @if ($editing) wire:sort="sortWidget" @endif>
        @foreach ($dashboard->widgets as $widget)
            @php($items = $this->contents[$widget->id] ?? collect())
            <section wire:key="widget-{{ $widget->id }}" wire:sort:item="{{ $widget->id }}"
                @class(['flex flex-col rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800/60', 'md:col-span-2' => $widget->wide, 'ring-2 ring-emerald-500/30' => $editing])>
                <header class="flex items-center gap-2 border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    @if ($editing)
                        <span class="cursor-grab text-zinc-400" wire:sort:handle><flux:icon.bars-2 variant="micro" /></span>
                    @endif
                    <flux:icon :name="$widget->icon()" variant="micro" class="text-emerald-700 dark:text-emerald-400" />
                    <h2 class="min-w-0 flex-1 truncate font-serif text-lg font-semibold">{{ $this->widgetTitle($widget) }}</h2>
                    @if ($editing)
                        <flux:button size="xs" variant="subtle" icon="cog-6-tooth" wire:click="editWidget({{ $widget->id }})" :tooltip="__('Configure')" />
                        <flux:button size="xs" variant="subtle" icon="trash" wire:click="removeWidget({{ $widget->id }})" wire:confirm="{{ __('Remove this widget?') }}" :tooltip="__('Remove')" />
                    @endif
                </header>

                <div class="flex-1 divide-y divide-zinc-100 dark:divide-zinc-700/60">
                    @if ($widget->type === 'cves')
                        @forelse ($items as $cve)
                            <a href="{{ $cve->nvdUrl() }}" target="_blank" rel="noopener noreferrer" class="flex items-center gap-2 px-4 py-2.5 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <span class="font-mono text-xs font-medium">{{ $cve->cve_id }}</span>
                                @if ($cve->cvss_score !== null)
                                    <flux:badge size="sm" :color="$cve->color()">{{ number_format($cve->cvss_score, 1) }}</flux:badge>
                                @endif
                                @if ($cve->in_kev)
                                    <flux:badge size="sm" color="red" variant="solid" icon="fire">{{ __('Exploited') }}</flux:badge>
                                @endif
                                <span class="min-w-0 flex-1 truncate text-xs text-zinc-500">{{ $cve->kev_product ? $cve->kev_vendor.' '.$cve->kev_product : Str::limit($cve->description, 60) }}</span>
                                <span class="shrink-0 text-xs text-zinc-400">{{ trans_choice(':count story|:count stories', $cve->stories) }}</span>
                            </a>
                        @empty
                            <p class="px-4 py-6 text-center text-sm text-zinc-500">{{ __('No vulnerabilities in the last two weeks.') }}</p>
                        @endforelse
                    @else
                        @forelse ($items as $item)
                            <button type="button" wire:click="open({{ $item->id }})" wire:key="w{{ $widget->id }}-e{{ $item->id }}"
                                class="block w-full px-4 py-2.5 text-start hover:bg-zinc-50 dark:hover:bg-zinc-800">
                                <span class="flex items-center gap-1.5 text-[0.7rem] font-medium tracking-wide text-zinc-500 uppercase">
                                    <span class="truncate">{{ $item->feed->displayTitle() }}</span>
                                    <span>&middot;</span>
                                    <span class="shrink-0 normal-case">{{ $item->published_at->shortRelativeToNowDiffForHumans() }}</span>
                                    @if ($widget->type === 'top' && ($item->top_sources ?? 1) > 1)
                                        <flux:badge size="sm" color="emerald" icon="newspaper">{{ $item->top_sources }}</flux:badge>
                                    @endif
                                </span>
                                <span @class(['mt-0.5 block font-serif leading-snug', 'font-semibold text-zinc-900 dark:text-zinc-50' => ! $item->isRead(), 'text-zinc-500 dark:text-zinc-400' => $item->isRead()])>
                                    {{ $item->title ?? __('Untitled') }}
                                </span>
                                <x-reader.signals :signals="array_merge($this->signals[$item->id] ?? [], ['sources' => 1])" class="mt-1" />
                            </button>
                        @empty
                            <p class="px-4 py-6 text-center text-sm text-zinc-500">{{ $unreadOnly ? __('All caught up here.') : __('Nothing here yet.') }}</p>
                        @endforelse
                    @endif
                </div>
            </section>
        @endforeach
    </div>

    {{-- Story --}}
    <flux:modal name="story" class="w-full max-w-3xl" x-on:close="$wire.set('openEntryId', null)">
        @if ($entry = $this->openEntry)
            <x-reader.article :entry="$entry" :signals="$this->signals[$entry->id] ?? app(\App\Services\Reader\EntryQuery::class)->signalsFor([$entry->id])[$entry->id]" class="!px-0 !py-2" />
        @endif
    </flux:modal>

    {{-- Widget editor --}}
    @if ($this->canEdit())
        <flux:modal name="widget" class="w-full md:w-[32rem]">
            <form wire:submit="saveWidget" class="space-y-5">
                <flux:heading size="lg">{{ $widgetId ? __('Configure widget') : __('Add widget') }}</flux:heading>

                <flux:select wire:model.live="wType" :label="__('Shows')">
                    <flux:select.option value="feed">{{ __('A feed') }}</flux:select.option>
                    <flux:select.option value="folder">{{ __('A folder') }}</flux:select.option>
                    <flux:select.option value="search">{{ __('A saved search') }}</flux:select.option>
                    <flux:select.option value="tag">{{ __('Tagged stories') }}</flux:select.option>
                    <flux:select.option value="starred">{{ __('Starred stories') }}</flux:select.option>
                    <flux:select.option value="top">{{ __('Top stories') }}</flux:select.option>
                    <flux:select.option value="cves">{{ __('Vulnerabilities') }}</flux:select.option>
                </flux:select>

                @switch($wType)
                    @case('feed')
                        <flux:select wire:model="wFeedId" :label="__('Feed')" :placeholder="__('Choose a feed')">
                            @foreach ($this->options['feeds'] as $feed)
                                <flux:select.option :value="$feed['id']">{{ $feed['title'] }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @break
                    @case('folder')
                        <flux:select wire:model="wFolderId" :label="__('Folder')" :placeholder="__('Choose a folder')">
                            @foreach ($this->options['folders'] as $folder)
                                <flux:select.option :value="$folder->id">{{ $folder->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @break
                    @case('tag')
                        <flux:select wire:model="wTagId" :label="__('Tag')" :placeholder="__('Choose a tag')">
                            @foreach ($this->options['tags'] as $tag)
                                <flux:select.option :value="$tag->id">#{{ $tag->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @break
                    @case('search')
                        <flux:input wire:model="wQuery" :label="__('Search for')" placeholder="ransomware" />
                        @break
                    @case('cves')
                        <flux:select wire:model="wFilter" :label="__('Which vulnerabilities')">
                            <flux:select.option value="kev">{{ __('Actively exploited (CISA KEV)') }}</flux:select.option>
                            <flux:select.option value="critical">{{ __('Critical (CVSS 9+)') }}</flux:select.option>
                            <flux:select.option value="mentioned">{{ __('All mentioned in the news') }}</flux:select.option>
                        </flux:select>
                        @break
                @endswitch

                @if ($this->role !== 'owner' && in_array($wType, ['feed', 'folder', 'tag', 'starred'], true))
                    <flux:text size="sm">{{ __('Widgets use :name’s feeds, folders and tags.', ['name' => $this->dashboard->owner->name]) }}</flux:text>
                @endif

                <flux:input wire:model="wTitle" :label="__('Title')" :placeholder="__('Optional')" />
                <div class="grid grid-cols-2 gap-4">
                    <flux:input type="number" min="3" max="20" wire:model="wLimit" :label="__('Items')" />
                    <div class="pt-7"><flux:checkbox wire:model="wWide" :label="__('Double width')" /></div>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-widget">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    {{-- Sharing --}}
    @if ($this->role === 'owner')
        <flux:modal name="share" class="w-full md:w-[34rem]">
            <div class="space-y-6">
                <flux:heading size="lg">{{ __('Share “:name”', ['name' => $dashboard->name]) }}</flux:heading>

                <form wire:submit="share" class="flex items-start gap-2">
                    <div class="flex-1">
                        <flux:input wire:model="shareEmail" type="email" :placeholder="__('Email of a Newsstand user')" />
                        <flux:error name="shareEmail" />
                    </div>
                    <flux:select wire:model="shareRole" class="!w-32">
                        <flux:select.option value="viewer">{{ __('Can view') }}</flux:select.option>
                        <flux:select.option value="editor">{{ __('Can edit') }}</flux:select.option>
                    </flux:select>
                    <flux:button type="submit" variant="primary" data-test="share-submit">{{ __('Share') }}</flux:button>
                </form>

                @if ($this->members->isNotEmpty())
                    <div class="-mx-2 space-y-0.5">
                        @foreach ($this->members as $member)
                            <div class="list-row" wire:key="member-{{ $member->id }}">
                                <span class="flex min-w-0 items-center gap-2">
                                    <flux:avatar size="xs" :name="$member->name" :initials="$member->initials()" />
                                    <span class="truncate">{{ $member->name }}</span>
                                    <span class="truncate text-xs text-zinc-500">{{ $member->email }}</span>
                                </span>
                                <span class="flex items-center gap-1">
                                    <flux:select size="sm" class="!w-28" wire:change="changeRole({{ $member->id }}, $event.target.value)">
                                        <flux:select.option value="viewer" :selected="$member->pivot->role === 'viewer'">{{ __('Can view') }}</flux:select.option>
                                        <flux:select.option value="editor" :selected="$member->pivot->role === 'editor'">{{ __('Can edit') }}</flux:select.option>
                                    </flux:select>
                                    <flux:button size="xs" variant="subtle" icon="x-mark" wire:click="removeMember({{ $member->id }})" :tooltip="__('Remove')" />
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif

                <flux:separator />

                <div class="space-y-3">
                    <flux:switch :checked="(bool) $dashboard->share_token" wire:click="togglePublicLink" data-test="public-link-toggle"
                        :label="__('Public read-only link')" :description="__('Anyone with the link can see headlines, without signing in.')" />
                    @if ($url = $dashboard->publicUrl())
                        <div class="flex items-center gap-2" x-data="{ copied: false }">
                            <flux:input readonly :value="$url" size="sm" class="flex-1" />
                            <flux:button size="sm" icon="clipboard" x-on:click="navigator.clipboard.writeText(@js($url)); copied = true; setTimeout(() => copied = false, 1500)">
                                <span x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></span>
                            </flux:button>
                            <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="regeneratePublicLink" :tooltip="__('New link (the old one stops working)')" />
                        </div>
                    @endif
                </div>
            </div>
        </flux:modal>

        <flux:modal name="rename" class="md:w-96">
            <form wire:submit="rename" class="space-y-5">
                <flux:heading size="lg">{{ __('Rename dashboard') }}</flux:heading>
                <flux:input wire:model="dashboardName" :label="__('Name')" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
