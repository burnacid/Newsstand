<?php

use App\Services\Reader\EntryQuery;
use App\Services\Reader\ReaderSource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/*
 * Feed navigation for the app sidebar: collections, folders and feeds with unread counts.
 */
new class extends Component {
    public string $current = '';

    public function mount(): void
    {
        $this->current = request()->routeIs('reader')
            ? ReaderSource::parse(request()->query('s'))->key()
            : '';
    }

    #[On('unread-changed')]
    public function refreshCounts(): void
    {
        unset($this->counts, $this->collections);
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
        $subscriptions = $user->subscriptions()->with('feed:id,title,url')->get()
            ->sortBy(fn ($s) => mb_strtolower($s->displayTitle()));

        return [
            'folders' => $user->folders()->get()->map(fn ($folder) => [
                'folder' => $folder,
                'subscriptions' => $subscriptions->where('folder_id', $folder->id)->values(),
            ])->filter(fn ($group) => $group['subscriptions']->isNotEmpty())->values(),
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
        $link = fn (string $source) => route('reader', ['s' => $source]);
    @endphp

    <flux:sidebar.nav>
        <flux:sidebar.item icon="newspaper" :href="$link('all')" :current="$current === 'all'" :badge="$this->badge($counts->sum())" wire:navigate>
            {{ __('All stories') }}
        </flux:sidebar.item>
        <flux:sidebar.item icon="star" :href="$link('starred')" :current="$current === 'starred'" :badge="$this->badge($this->collections['starred'])" wire:navigate>
            {{ __('Starred') }}
        </flux:sidebar.item>
        <flux:sidebar.item icon="bookmark" :href="$link('later')" :current="$current === 'later'" :badge="$this->badge($this->collections['later'])" wire:navigate>
            {{ __('Read later') }}
        </flux:sidebar.item>
    </flux:sidebar.nav>

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
