<?php

use App\Actions\Feeds\Organizer;
use App\Models\Dashboard;
use App\Models\Folder;
use App\Models\Subscription;
use App\Models\Tag;
use App\Models\Team;
use App\Services\Teams\FeedAccess;
use App\Services\Watchlist\WatchlistMatcher;
use App\Services\Reader\EntryQuery;
use App\Services\Reader\ReaderSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * Feed navigation for the app sidebar: collections, a group per team (its dashboards and folders),
 * personal folders and feeds with unread counts.
 */
new class extends Component {
    public string $current = '';

    public function mount(): void
    {
        $dashboard = request()->route('dashboard');

        $this->current = match (true) {
            request()->routeIs('reader') => ReaderSource::parse(request()->query('s'))->key(),
            request()->routeIs('dashboards.show') => 'dashboard:'.($dashboard instanceof Dashboard ? $dashboard->id : $dashboard),
            default => '',
        };
    }

    #[On('dashboards-changed')]
    public function refreshTree(): void
    {
        unset($this->tree);
    }

    #[On('unread-changed')]
    public function refreshCounts(): void
    {
        unset($this->counts, $this->collections, $this->watching);
    }

    #[Computed]
    public function watching(): bool
    {
        return WatchlistMatcher::itemsFor(Auth::user())->exists();
    }

    #[On('tags-changed')]
    public function refreshTags(): void
    {
        unset($this->tags, $this->tree);
    }

    #[Computed]
    public function tags(): Collection
    {
        return Auth::user()->tags()->withCount('entries')->get();
    }

    #[On('reader-source-changed')]
    public function sourceChanged(string $source): void
    {
        $this->current = $source;
    }

    #[Computed]
    public function counts(): Collection
    {
        return app(EntryQuery::class)->unreadCounts(Auth::user());
    }

    #[Computed]
    public function collections(): array
    {
        return app(EntryQuery::class)->collectionCounts(Auth::user());
    }

    #[Computed]
    public function tree(): array
    {
        $user = Auth::user();
        $subscriptions = Organizer::sortSubscriptions($user->subscriptions()->with('feed:id,title,url')->get());

        $folderGroup = fn (Collection $subs) => fn (Folder $folder) => [
            'folder' => $folder,
            'subscriptions' => $subs->where('folder_id', $folder->id)->values(),
        ];
        $hasFeeds = fn (array $group) => $group['subscriptions']->isNotEmpty();

        // One group per team: its dashboards, then its folders and unfiled feeds.
        $teamIds = FeedAccess::teamIds($user);
        $teamSubscriptions = Organizer::sortSubscriptions(
            Subscription::query()->whereIn('team_id', $teamIds)->with('feed:id,title,url')->get()
        );
        $teamFolders = Folder::query()->whereIn('team_id', $teamIds)->orderBy('position')->orderBy('name')->get();
        // The default dashboard comes first wherever it is listed.
        $defaultFirst = fn (Collection $dashboards) => $dashboards
            ->sortBy(fn (Dashboard $d) => [$d->id === $user->default_dashboard_id ? 0 : 1, mb_strtolower($d->name)])->values();

        $teamDashboards = Dashboard::query()->whereIn('team_id', $teamIds)->get(['id', 'name', 'team_id']);
        $teamTags = Tag::query()->whereIn('team_id', $teamIds)->withCount('entries')->orderBy('name')->get();

        // Personal: your own without a team, and ones shared with you directly (unless listed under one of your teams).
        $personalDashboards = Dashboard::query()
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('owner_id', $user->id)->whereNull('team_id'))
                ->orWhere(fn ($q) => $q
                    ->whereIn('id', DB::table('dashboard_user')->select('dashboard_id')->where('user_id', $user->id))
                    ->where(fn ($q) => $q->whereNull('team_id')->orWhereNotIn('team_id', $teamIds))))
            ->get(['id', 'name', 'team_id']);

        $teams = Team::query()->whereIn('id', $teamIds)->orderBy('name')->get(['id', 'name'])
            ->map(fn (Team $team) => [
                'team' => $team,
                'dashboards' => $defaultFirst($teamDashboards->where('team_id', $team->id)),
                'tags' => $teamTags->where('team_id', $team->id)->values(),
                'folders' => $teamFolders->where('team_id', $team->id)
                    ->map($folderGroup($teamSubscriptions))->filter($hasFeeds)->values(),
                'unfiled' => $teamSubscriptions->where('team_id', $team->id)->whereNull('folder_id')->values(),
            ])
            ->filter(fn (array $group) => $group['dashboards']->isNotEmpty() || $group['tags']->isNotEmpty()
                || $group['folders']->isNotEmpty() || $group['unfiled']->isNotEmpty())
            ->values();

        return [
            'dashboards' => $defaultFirst($personalDashboards),
            'teams' => $teams,
            'folders' => $user->folders()->get()->map($folderGroup($subscriptions))->filter($hasFeeds)->values(),
            'unfiled' => $subscriptions->whereNull('folder_id')->values(),
        ];
    }

    public function badge(int $count): ?string
    {
        return $count > 0 ? ($count > 999 ? '999+' : (string) $count) : null;
    }
}; ?>

<div>
    @php
        $counts = $this->counts;
        $defaultDashboardId = auth()->user()->default_dashboard_id;
        $link = fn (string $source) => route('reader', ['s' => $source]);
    @endphp

    <flux:sidebar.nav>
        <flux:sidebar.item icon="newspaper" :href="$link('all')" :current="$current === 'all'" :badge="$this->badge($counts->sum())" wire:navigate>
            {{ __('All stories') }}
        </flux:sidebar.item>
        <flux:sidebar.item icon="fire" :href="$link('top')" :current="$current === 'top'" wire:navigate>
            {{ __('Top stories') }}
        </flux:sidebar.item>
        @if ($this->watching)
            <flux:sidebar.item icon="eye" :href="$link('watchlist')" :current="$current === 'watchlist'" wire:navigate>
                {{ __('Your watchlist') }}
            </flux:sidebar.item>
        @endif
        <flux:sidebar.item icon="star" :href="$link('starred')" :current="$current === 'starred'" :badge="$this->badge($this->collections['starred'])" wire:navigate>
            {{ __('Starred') }}
        </flux:sidebar.item>
        <flux:sidebar.item icon="bookmark" :href="$link('later')" :current="$current === 'later'" :badge="$this->badge($this->collections['later'])" wire:navigate>
            {{ __('Read later') }}
        </flux:sidebar.item>
    </flux:sidebar.nav>

    @if ($this->tree['dashboards']->isNotEmpty())
        <flux:sidebar.group expandable :heading="__('Dashboards')" class="mt-3 grid" data-test="nav-dashboards">
            @foreach ($this->tree['dashboards'] as $dashboard)
                <flux:sidebar.item :icon="$dashboard->id === $defaultDashboardId ? 'star' : 'squares-2x2'" :href="route('dashboards.show', $dashboard)" :current="$current === 'dashboard:'.$dashboard->id"
                    wire:navigate wire:key="nav-dashboard-{{ $dashboard->id }}" data-test="nav-dashboard">
                    {{ $dashboard->name }}
                </flux:sidebar.item>
            @endforeach
        </flux:sidebar.group>
    @endif

    @foreach ($this->tree['teams'] as $group)
        <flux:sidebar.group expandable :heading="$group['team']->name" class="mt-3 grid" wire:key="nav-team-{{ $group['team']->id }}" data-test="nav-team">
            @foreach ($group['dashboards'] as $dashboard)
                <flux:sidebar.item :icon="$dashboard->id === $defaultDashboardId ? 'star' : 'squares-2x2'" :href="route('dashboards.show', $dashboard)" :current="$current === 'dashboard:'.$dashboard->id"
                    wire:navigate wire:key="nav-dashboard-{{ $dashboard->id }}" data-test="nav-dashboard">
                    {{ $dashboard->name }}
                </flux:sidebar.item>
            @endforeach
            @foreach ($group['folders'] as $folderGroup)
                @php($folder = $folderGroup['folder'])
                <flux:sidebar.item icon="folder" :href="$link('folder:'.$folder->id)" :current="$current === 'folder:'.$folder->id"
                    :badge="$this->badge($folderGroup['subscriptions']->sum(fn ($s) => $counts[$s->feed_id] ?? 0))" wire:navigate wire:key="nav-folder-{{ $folder->id }}">
                    {{ $folder->name }}
                </flux:sidebar.item>
                @foreach ($folderGroup['subscriptions'] as $subscription)
                    <flux:sidebar.item class="ps-7" :href="$link('feed:'.$subscription->feed_id)" :current="$current === 'feed:'.$subscription->feed_id"
                        :badge="$this->badge($counts[$subscription->feed_id] ?? 0)" wire:navigate wire:key="nav-feed-{{ $subscription->id }}">
                        {{ $subscription->displayTitle() }}
                    </flux:sidebar.item>
                @endforeach
            @endforeach
            @foreach ($group['unfiled'] as $subscription)
                <flux:sidebar.item :href="$link('feed:'.$subscription->feed_id)" :current="$current === 'feed:'.$subscription->feed_id"
                    :badge="$this->badge($counts[$subscription->feed_id] ?? 0)" wire:navigate wire:key="nav-feed-{{ $subscription->id }}">
                    {{ $subscription->displayTitle() }}
                </flux:sidebar.item>
            @endforeach
            @foreach ($group['tags'] as $tag)
                <flux:sidebar.item :href="$link('tag:'.$tag->id)" :current="$current === 'tag:'.$tag->id"
                    :badge="$this->badge($tag->entries_count)" wire:navigate wire:key="nav-tag-{{ $tag->id }}" data-test="nav-team-tag">
                    <span class="flex items-center gap-2">
                        <span @class(['size-2 shrink-0 rounded-full', "bg-{$tag->color}-500"])></span>
                        {{ $tag->name }}
                    </span>
                </flux:sidebar.item>
            @endforeach
        </flux:sidebar.group>
    @endforeach

    @foreach ($this->tree['folders'] as $group)
        @php($folder = $group['folder'])
        <flux:sidebar.group expandable :heading="$folder->name" class="mt-3 grid" wire:key="nav-folder-{{ $folder->id }}">
            <flux:sidebar.item icon="folder" :href="$link('folder:'.$folder->id)" :current="$current === 'folder:'.$folder->id"
                :badge="$this->badge($group['subscriptions']->sum(fn ($s) => $counts[$s->feed_id] ?? 0))" wire:navigate>
                {{ __('All in :folder', ['folder' => $folder->name]) }}
            </flux:sidebar.item>
            @foreach ($group['subscriptions'] as $subscription)
                <flux:sidebar.item :href="$link('feed:'.$subscription->feed_id)" :current="$current === 'feed:'.$subscription->feed_id"
                    :badge="$this->badge($counts[$subscription->feed_id] ?? 0)" wire:navigate wire:key="nav-feed-{{ $subscription->id }}">
                    {{ $subscription->displayTitle() }}
                </flux:sidebar.item>
            @endforeach
        </flux:sidebar.group>
    @endforeach

    @if ($this->tags->isNotEmpty())
        <flux:sidebar.group expandable :heading="__('Tags')" class="mt-3 grid">
            @foreach ($this->tags as $tag)
                <flux:sidebar.item :href="$link('tag:'.$tag->id)" :current="$current === 'tag:'.$tag->id"
                    :badge="$this->badge($tag->entries_count)" wire:navigate wire:key="nav-tag-{{ $tag->id }}">
                    <span class="flex items-center gap-2">
                        <span @class(['size-2 shrink-0 rounded-full', "bg-{$tag->color}-500"])></span>
                        {{ $tag->name }}
                    </span>
                </flux:sidebar.item>
            @endforeach
        </flux:sidebar.group>
    @endif

    @if ($this->tree['unfiled']->isNotEmpty())
        <flux:sidebar.group expandable :heading="$this->tree['folders']->isEmpty() ? __('Feeds') : __('Unfiled')" class="mt-3 grid">
            @foreach ($this->tree['unfiled'] as $subscription)
                <flux:sidebar.item :href="$link('feed:'.$subscription->feed_id)" :current="$current === 'feed:'.$subscription->feed_id"
                    :badge="$this->badge($counts[$subscription->feed_id] ?? 0)" wire:navigate wire:key="nav-feed-{{ $subscription->id }}">
                    {{ $subscription->displayTitle() }}
                </flux:sidebar.item>
            @endforeach
        </flux:sidebar.group>
    @endif
</div>
