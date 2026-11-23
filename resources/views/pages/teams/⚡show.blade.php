<?php

use App\Actions\Feeds\Organizer;
use App\Actions\Feeds\SubscribeToFeed;
use App\Actions\Reader\TagEntries;
use App\Models\Tag;
use App\Models\Folder;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Feeds\FeedDiscovery;
use App\Services\Feeds\FeedRefresher;
use App\Services\Teams\TeamManager;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Team')] class extends Component {
    #[Locked]
    public int $teamId;

    // Members
    public string $memberEmail = '';
    public string $memberRole = 'member';
    public string $inviteRole = 'member';

    // Shared feeds
    public string $feedUrl = '';
    public string $feedFolderId = '';
    public string $folderName = '';

    /** @var list<array{url: string, title: string|null}> */
    public array $candidates = [];

    // Edit a shared feed
    public ?int $editingId = null;
    public string $editTitle = '';
    public string $editFolderId = '';
    public bool $editFullText = false;

    // Team tags
    public string $newTag = '';

    // Settings
    public string $teamName = '';

    public function mount(Team $team): void
    {
        abort_unless($team->isMember(Auth::user()), 404);

        $this->teamId = $team->id;
        $this->teamName = $team->name;
    }

    #[Computed]
    public function team(): Team
    {
        $team = Team::findOrFail($this->teamId);

        // Removed while the page was open.
        abort_unless($team->isMember(Auth::user()), 404);

        return $team;
    }

    #[Computed]
    public function role(): ?string
    {
        return $this->team->roleOf(Auth::user());
    }

    #[Computed]
    public function isAdmin(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }

    #[Computed]
    public function members(): Collection
    {
        return $this->team->members()
            ->orderByRaw("case team_user.role when 'owner' then 0 when 'admin' then 1 else 2 end")
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function invitations(): Collection
    {
        return $this->team->invitations()->where('expires_at', '>', now())->latest()->get();
    }

    #[Computed]
    public function folders(): Collection
    {
        $subscriptions = Organizer::sortSubscriptions($this->team->subscriptions()->with('feed')->get());

        return $this->team->folders()->get()
            ->each(fn (Folder $folder) => $folder->setRelation('subscriptions', $subscriptions->where('folder_id', $folder->id)->values()));
    }

    // --- Members ---

    public function addMember(TeamManager $teams): void
    {
        $this->authorizeAdmin();
        $this->validate(['memberEmail' => ['required', 'email'], 'memberRole' => ['in:member,admin']]);

        $user = User::where('email', Str::lower(trim($this->memberEmail)))->first();

        if (! $user) {
            $this->addError('memberEmail', __('There is no Newsstand user with that email address. Send them an invite link instead.'));

            return;
        }

        if ($this->team->isMember($user)) {
            $this->addError('memberEmail', __(':name is already in this team.', ['name' => $user->name]));

            return;
        }

        // Only the owner hands out admin rights.
        $teams->addMember($this->team, $user, $this->role === 'owner' ? $this->memberRole : 'member');

        $this->reset('memberEmail', 'memberRole');
        unset($this->members);
        Flux::toast(variant: 'success', text: __('Added :name.', ['name' => $user->name]));
    }

    public function changeRole(int $userId, string $role, TeamManager $teams): void
    {
        $target = $this->members->firstWhere('id', $userId);

        if ($target && $teams->changeRole($this->team, Auth::user(), $target, $role)) {
            unset($this->members);
        } else {
            Flux::toast(variant: 'danger', text: __('You can’t change that member’s role.'));
        }
    }

    public function removeMember(int $userId, TeamManager $teams): void
    {
        $target = $this->members->firstWhere('id', $userId);

        if ($target && $teams->removeMember($this->team, Auth::user(), $target)) {
            unset($this->members);
            Flux::toast(text: __('Removed :name.', ['name' => $target->name]));
        }
    }

    public function makeOwner(int $userId, TeamManager $teams): void
    {
        $target = $this->members->firstWhere('id', $userId);

        if ($target && $teams->transferOwnership($this->team, Auth::user(), $target)) {
            unset($this->members, $this->role, $this->isAdmin);
            Flux::toast(variant: 'success', text: __(':name now owns the team.', ['name' => $target->name]));
        }
    }

    public function createInvite(TeamManager $teams): void
    {
        $this->authorizeAdmin();

        $teams->invite($this->team, Auth::user(), $this->inviteRole === 'admin' ? 'admin' : 'member');
        unset($this->invitations);
    }

    public function revokeInvite(int $id): void
    {
        $this->authorizeAdmin();

        $this->team->invitations()->whereKey($id)->delete();
        unset($this->invitations);
    }

    public function leave(TeamManager $teams): void
    {
        if (! $teams->leave($this->team, Auth::user())) {
            Flux::toast(variant: 'danger', text: __('The owner can’t leave. Hand the team over to someone else, or delete it.'));

            return;
        }

        $this->redirectRoute('teams.index', navigate: true);
    }

    // --- Shared feeds ---

    public function createFolder(): void
    {
        $this->authorizeAdmin();
        $this->validate(['folderName' => [
            'required', 'string', 'max:100',
            Rule::unique('folders', 'name')->where('team_id', $this->teamId),
        ]]);

        $folder = $this->team->folders()->create([
            'name' => $this->folderName,
            'position' => (int) $this->team->folders()->max('position') + 1,
        ]);

        $this->feedFolderId = (string) $folder->id;
        $this->reset('folderName');
        unset($this->folders);
        $this->dispatch('unread-changed');
    }

    public function renameFolder(int $id, string $name): void
    {
        $this->authorizeAdmin();

        $name = trim($name);
        $folder = $this->team->folders()->findOrFail($id);

        if ($name === '' || mb_strlen($name) > 100 || $this->team->folders()->where('name', $name)->whereKeyNot($id)->exists()) {
            Flux::toast(variant: 'danger', text: __('Pick another name.'));

            return;
        }

        $folder->update(['name' => $name]);
        unset($this->folders);
        $this->dispatch('unread-changed');
    }

    public function deleteFolder(int $id): void
    {
        $this->authorizeAdmin();

        $folder = $this->team->folders()->findOrFail($id);
        // Team feeds always live in a team folder: removing the folder removes its feeds.
        $this->team->subscriptions()->where('folder_id', $folder->id)->delete();
        $folder->delete();

        unset($this->folders);
        $this->dispatch('unread-changed');
    }

    // --- Team tags ---

    /** Shared labels: any member puts them on stories, owners and admins manage them. */
    #[Computed]
    public function tags(): Collection
    {
        return $this->team->tags()->withCount('entries')->get();
    }

    public function createTag(TagEntries $tagging): void
    {
        $this->authorizeAdmin();
        $this->validate(['newTag' => ['required', 'string', 'max:50']], [], ['newTag' => __('tag')]);

        $tagging->createForTeam($this->team, $this->newTag);

        $this->reset('newTag');
        unset($this->tags);
        $this->dispatch('tags-changed');
    }

    public function renameTag(int $id, string $name): void
    {
        $this->authorizeAdmin();

        $name = trim($name);
        $tag = $this->team->tags()->findOrFail($id);

        if ($name === '' || mb_strlen($name) > 50 || $this->team->tags()->where('name', $name)->whereKeyNot($id)->exists()) {
            Flux::toast(variant: 'danger', text: __('Pick another name.'));

            return;
        }

        $tag->update(['name' => $name]);
        unset($this->tags);
        $this->dispatch('tags-changed');
    }

    public function recolorTag(int $id, string $color): void
    {
        $this->authorizeAdmin();

        if (in_array($color, Tag::COLORS, true)) {
            $this->team->tags()->findOrFail($id)->update(['color' => $color]);
            unset($this->tags);
            $this->dispatch('tags-changed');
        }
    }

    public function deleteTag(int $id): void
    {
        $this->authorizeAdmin();
        $this->team->tags()->findOrFail($id)->delete();

        unset($this->tags);
        $this->dispatch('tags-changed');
    }

    public function addFeed(FeedDiscovery $discovery, SubscribeToFeed $subscribe): void
    {
        $this->authorizeAdmin();
        $this->validate([
            'feedUrl' => ['required', 'string', 'max:2048'],
            'feedFolderId' => ['required', Rule::exists('folders', 'id')->where('team_id', $this->teamId)],
        ], ['feedFolderId.required' => __('Create a team folder first.')]);

        $this->candidates = [];

        try {
            $found = $discovery->discover($this->feedUrl);
        } catch (\Throwable $e) {
            $this->addError('feedUrl', __('Could not load that URL: :message', ['message' => $e->getMessage()]));

            return;
        }

        if ($found === []) {
            $this->addError('feedUrl', __('No RSS or Atom feed found at that address.'));

            return;
        }

        if (count($found) === 1) {
            $this->subscribeTo($subscribe, $found[0]->url, $found[0]->title);

            return;
        }

        $this->candidates = array_map(fn ($c) => $c->toArray(), $found);
    }

    public function choose(int $index, SubscribeToFeed $subscribe): void
    {
        $this->authorizeAdmin();

        if ($candidate = $this->candidates[$index] ?? null) {
            $this->subscribeTo($subscribe, $candidate['url'], $candidate['title']);
        }
    }

    public function removeFeed(int $subscriptionId): void
    {
        $this->authorizeAdmin();

        $this->team->subscriptions()->whereKey($subscriptionId)->delete();
        unset($this->folders);
        $this->dispatch('unread-changed');
    }

    public function refreshFeed(int $subscriptionId, FeedRefresher $refresher): void
    {
        $this->authorizeAdmin();

        $subscription = $this->findTeamSubscription($subscriptionId);
        $refresher->refresh($subscription->feed);

        unset($this->folders);
        $this->dispatch('unread-changed');

        $subscription->feed->last_error
            ? Flux::toast(variant: 'danger', text: $subscription->feed->last_error)
            : Flux::toast(variant: 'success', text: __('Feed refreshed.'));
    }

    public function editFeed(int $subscriptionId): void
    {
        $this->authorizeAdmin();

        $subscription = $this->findTeamSubscription($subscriptionId);

        $this->editingId = $subscription->id;
        $this->editTitle = $subscription->custom_title ?? '';
        $this->editFolderId = (string) $subscription->folder_id;
        $this->editFullText = $subscription->feed->full_text;
        $this->resetErrorBag();

        Flux::modal('edit-team-feed')->show();
    }

    public function saveFeed(): void
    {
        $this->authorizeAdmin();
        $this->validate([
            'editTitle' => ['nullable', 'string', 'max:250'],
            // Team feeds always live in one of the team's folders.
            'editFolderId' => ['required', Rule::exists('folders', 'id')->where('team_id', $this->teamId)],
        ]);

        $subscription = $this->findTeamSubscription($this->editingId);
        $folderId = (int) $this->editFolderId;

        $subscription->update([
            'custom_title' => $this->editTitle !== '' ? $this->editTitle : null,
            'folder_id' => $folderId,
            // Moving to another folder puts the feed at the end of it.
            'position' => $subscription->folder_id === $folderId
                ? $subscription->position
                : (int) $this->team->subscriptions()->where('folder_id', $folderId)->max('position') + 1,
        ]);

        // Full-text extraction is a property of the (shared) feed.
        $subscription->feed->update(['full_text' => $this->editFullText]);

        $this->reset('editingId', 'editTitle', 'editFolderId', 'editFullText');
        unset($this->folders);
        $this->dispatch('unread-changed');
        Flux::modal('edit-team-feed')->close();
    }

    // --- Settings ---

    public function rename(): void
    {
        $this->authorizeAdmin();
        $this->validate(['teamName' => ['required', 'string', 'max:100']]);

        $this->team->update(['name' => trim($this->teamName)]);
        unset($this->team);
        $this->dispatch('unread-changed');
        Flux::toast(variant: 'success', text: __('Team renamed.'));
    }

    public function deleteTeam(): void
    {
        abort_unless($this->role === 'owner', 403);

        $this->team->delete();

        $this->redirectRoute('teams.index', navigate: true);
    }

    private function subscribeTo(SubscribeToFeed $subscribe, string $url, ?string $title): void
    {
        $folder = $this->team->folders()->findOrFail((int) $this->feedFolderId);
        $subscription = $subscribe->handle(Auth::user(), $url, $folder, $title);

        $this->reset('feedUrl', 'candidates');
        unset($this->folders);
        $this->dispatch('unread-changed');

        Flux::toast(variant: 'success', text: __('Added :title for everyone in the team.', ['title' => $subscription->displayTitle()]));
    }

    private function findTeamSubscription(?int $id): Subscription
    {
        return $this->team->subscriptions()->with('feed')->findOrFail($id);
    }

    private function authorizeAdmin(): void
    {
        abort_unless($this->isAdmin, 403);
    }
}; ?>

<div class="mx-auto w-full max-w-4xl space-y-8">
    @php($team = $this->team)

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:link :href="route('teams.index')" wire:navigate class="text-sm">{{ __('← Teams') }}</flux:link>
            <flux:heading size="xl" level="1" class="mt-1">{{ $team->name }}</flux:heading>
            <flux:text class="mt-1">{{ __('You are :role of this team.', ['role' => __($this->role === 'owner' ? 'the owner' : ($this->role === 'admin' ? 'an admin' : 'a member'))]) }}</flux:text>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button icon="eye" :href="route('watchlist', ['team' => $team->id])" wire:navigate>{{ __('Team watchlist') }}</flux:button>
            @if ($this->isAdmin)
                <flux:button icon="bell" :href="route('alerts.edit', ['team' => $team->id])" wire:navigate>{{ __('Team alerts') }}</flux:button>
                <flux:button icon="adjustments-horizontal" :href="route('rules', ['team' => $team->id])" wire:navigate data-test="team-rules-link">{{ __('Team rules') }}</flux:button>
            @endif
        </div>
    </div>

    {{-- Shared feeds --}}
    <flux:card class="space-y-5">
        <div>
            <flux:heading size="lg">{{ __('Shared feeds') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Feeds in team folders show up in everyone’s reader, under the team’s name.') }}</flux:text>
        </div>

        @if ($this->isAdmin)
            <form wire:submit="addFeed" class="grid gap-3 sm:grid-cols-[1fr_12rem_auto] sm:items-start">
                <div>
                    <flux:input wire:model="feedUrl" :placeholder="__('Website or feed URL')" data-test="team-feed-url" />
                    <flux:error name="feedUrl" />
                </div>
                <div>
                    <flux:select wire:model="feedFolderId" :placeholder="__('Folder')" data-test="team-feed-folder">
                        @foreach ($this->folders as $folder)
                            <flux:select.option value="{{ $folder->id }}">{{ $folder->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="feedFolderId" />
                </div>
                <flux:button type="submit" variant="primary" data-test="team-feed-add">{{ __('Add feed') }}</flux:button>
            </form>

            @if ($candidates !== [])
                <div class="space-y-1">
                    <flux:text class="text-sm">{{ __('That site has several feeds. Pick one:') }}</flux:text>
                    @foreach ($candidates as $index => $candidate)
                        <button type="button" wire:click="choose({{ $index }})" class="block w-full rounded-lg border border-zinc-200 px-3 py-2 text-start text-sm hover:border-emerald-500 dark:border-zinc-700" wire:key="cand-{{ $index }}">
                            <span class="font-medium">{{ $candidate['title'] ?: $candidate['url'] }}</span>
                            <span class="block truncate text-xs text-zinc-500">{{ $candidate['url'] }}</span>
                        </button>
                    @endforeach
                </div>
            @endif

            <form wire:submit="createFolder" class="flex items-start gap-2">
                <div class="flex-1">
                    <flux:input size="sm" wire:model="folderName" :placeholder="__('New team folder, e.g. Threat intel')" data-test="team-folder-name" />
                    <flux:error name="folderName" />
                </div>
                <flux:button size="sm" type="submit" icon="folder-plus" data-test="team-folder-add">{{ __('Add folder') }}</flux:button>
            </form>
        @endif

        <div class="space-y-4">
            @forelse ($this->folders as $folder)
                <div wire:key="team-folder-{{ $folder->id }}" x-data="{ renaming: false, name: @js($folder->name) }">
                    <div class="flex items-center gap-2">
                        <flux:icon.folder class="size-4 text-zinc-400" />
                        <span class="font-medium" x-show="! renaming">{{ $folder->name }}</span>
                        @if ($this->isAdmin)
                            <form x-show="renaming" x-cloak class="flex gap-1" x-on:submit.prevent="$wire.renameFolder({{ $folder->id }}, name); renaming = false">
                                <flux:input size="sm" x-model="name" />
                                <flux:button size="sm" type="submit">{{ __('Save') }}</flux:button>
                            </form>
                            <flux:spacer />
                            <flux:button size="xs" variant="subtle" icon="pencil" x-on:click="renaming = ! renaming" :tooltip="__('Rename')" />
                            <flux:button size="xs" variant="subtle" icon="trash" wire:click="deleteFolder({{ $folder->id }})"
                                wire:confirm="{{ __('Delete :folder and remove its feeds for everyone in the team?', ['folder' => $folder->name]) }}" :tooltip="__('Delete folder')" />
                        @endif
                    </div>
                    <div class="mt-1 ms-6 space-y-0.5">
                        @forelse ($folder->subscriptions as $subscription)
                            @php($feed = $subscription->feed)
                            <div class="flex items-center gap-2 text-sm" wire:key="team-sub-{{ $subscription->id }}" data-test="team-sub-{{ $subscription->id }}">
                                <span class="min-w-0 flex-1 truncate">{{ $subscription->displayTitle() }}</span>
                                <span class="hidden shrink-0 text-xs text-zinc-500 sm:inline">{{ $feed->last_fetched_at?->diffForHumans() ?? __('Pending') }}</span>
                                @if ($feed->last_fetched_at === null)
                                    <flux:badge size="sm" color="zinc">{{ __('Queued') }}</flux:badge>
                                @elseif (! $feed->isHealthy())
                                    <flux:tooltip :content="$feed->last_error">
                                        <flux:badge size="sm" color="red">{{ trans_choice(':count error|:count errors', $feed->error_count) }}</flux:badge>
                                    </flux:tooltip>
                                @endif
                                @if ($this->isAdmin)
                                    <flux:dropdown position="bottom" align="end">
                                        <flux:button size="xs" variant="subtle" icon="ellipsis-horizontal" data-test="team-sub-menu" />
                                        <flux:menu>
                                            <flux:menu.item icon="arrow-path" wire:click="refreshFeed({{ $subscription->id }})" data-test="team-sub-refresh">{{ __('Refresh now') }}</flux:menu.item>
                                            <flux:menu.item icon="pencil-square" wire:click="editFeed({{ $subscription->id }})" data-test="team-sub-edit">{{ __('Edit') }}</flux:menu.item>
                                            @if ($feed->site_url)
                                                <flux:menu.item icon="arrow-top-right-on-square" :href="$feed->site_url" target="_blank">{{ __('Visit site') }}</flux:menu.item>
                                            @endif
                                            <flux:menu.separator />
                                            <flux:menu.item icon="trash" variant="danger" wire:click="removeFeed({{ $subscription->id }})"
                                                wire:confirm="{{ __('Remove :feed for everyone in the team?', ['feed' => $subscription->displayTitle()]) }}">{{ __('Remove') }}</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                @endif
                            </div>
                        @empty
                            <flux:text class="text-sm">{{ __('No feeds in this folder yet.') }}</flux:text>
                        @endforelse
                    </div>
                </div>
            @empty
                <flux:text>{{ $this->isAdmin ? __('Create a team folder, then add feeds to it.') : __('The team has no shared feeds yet.') }}</flux:text>
            @endforelse
        </div>
    </flux:card>

    @if ($this->isAdmin)
        <flux:modal name="edit-team-feed" class="md:w-96">
            <form wire:submit="saveFeed" class="space-y-5">
                <flux:heading size="lg">{{ __('Edit shared feed') }}</flux:heading>
                <flux:input wire:model="editTitle" :label="__('Custom title')" :placeholder="__('Leave empty to use the feed title')" />
                <flux:select wire:model="editFolderId" :label="__('Team folder')">
                    @foreach ($this->folders as $folder)
                        <flux:select.option value="{{ $folder->id }}">{{ $folder->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:error name="editFolderId" />
                <flux:switch wire:model="editFullText" :label="__('Fetch full article text')"
                    :description="__('For feeds that only publish a summary. Applies to everyone subscribed to this feed.')" />
                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="team-sub-save">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endif

    {{-- Team tags --}}
    <flux:card class="space-y-4" data-test="team-tags">
        <div>
            <flux:heading size="lg">{{ __('Tags') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Shared labels for stories. Everyone in the team can put them on stories from the reader’s tag menu, and team dashboards can show them.') }}</flux:text>
        </div>

        @if ($this->isAdmin)
            <form wire:submit="createTag" class="flex items-start gap-2">
                <div class="flex-1">
                    <flux:input size="sm" wire:model="newTag" :placeholder="__('New team tag, e.g. needs-patching')" data-test="team-tag-name" />
                    <flux:error name="newTag" />
                </div>
                <flux:button size="sm" type="submit" icon="tag" data-test="team-tag-add">{{ __('Add tag') }}</flux:button>
            </form>
        @endif

        <div class="-mx-2 space-y-0.5">
            @forelse ($this->tags as $tag)
                <div class="list-row" wire:key="team-tag-{{ $tag->id }}" x-data="{ renaming: false, name: @js($tag->name) }">
                    <span class="flex min-w-0 items-center gap-2">
                        <span @class(['size-2.5 shrink-0 rounded-full', "bg-{$tag->color}-500"])></span>
                        <a href="{{ route('reader', ['s' => 'tag:'.$tag->id]) }}" wire:navigate class="truncate font-medium hover:underline" x-show="! renaming">#{{ $tag->name }}</a>
                        @if ($this->isAdmin)
                            <form x-show="renaming" x-cloak class="flex gap-1" x-on:submit.prevent="$wire.renameTag({{ $tag->id }}, name); renaming = false">
                                <flux:input size="sm" x-model="name" />
                                <flux:button size="sm" type="submit">{{ __('Save') }}</flux:button>
                            </form>
                        @endif
                        <span class="shrink-0 text-xs text-zinc-500">{{ trans_choice(':count story|:count stories', $tag->entries_count) }}</span>
                    </span>
                    @if ($this->isAdmin)
                        <span class="flex items-center gap-1">
                            <flux:dropdown align="end">
                                <flux:button size="xs" variant="subtle" icon="swatch" :aria-label="__('Color')" :title="__('Color')" />
                                <flux:menu>
                                    @foreach (\App\Models\Tag::COLORS as $color)
                                        <flux:menu.item wire:click="recolorTag({{ $tag->id }}, '{{ $color }}')" :icon="$tag->color === $color ? 'check' : null">
                                            <span class="flex items-center gap-2"><span @class(['size-2.5 rounded-full', "bg-{$color}-500"])></span>{{ ucfirst($color) }}</span>
                                        </flux:menu.item>
                                    @endforeach
                                </flux:menu>
                            </flux:dropdown>
                            <flux:button size="xs" variant="subtle" icon="pencil" x-on:click="renaming = ! renaming" :tooltip="__('Rename')" />
                            <flux:button size="xs" variant="subtle" icon="trash" wire:click="deleteTag({{ $tag->id }})" data-test="team-tag-delete"
                                wire:confirm="{{ __('Delete #:tag? It is removed from all stories for everyone in the team.', ['tag' => $tag->name]) }}" :tooltip="__('Delete')" />
                        </span>
                    @endif
                </div>
            @empty
                <flux:text class="px-2">{{ $this->isAdmin ? __('No team tags yet.') : __('The team has no tags yet.') }}</flux:text>
            @endforelse
        </div>
    </flux:card>

    {{-- Members --}}
    <flux:card class="space-y-5">
        <flux:heading size="lg">{{ __('Members') }}</flux:heading>

        <div class="-mx-2 space-y-0.5">
            @foreach ($this->members as $member)
                @php($memberRole = $member->pivot->role)
                <div class="flex items-center gap-3 rounded-lg px-2 py-1.5" wire:key="member-{{ $member->id }}">
                    <flux:avatar size="sm" :name="$member->name" />
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-sm font-medium">{{ $member->name }} @if ($member->is(auth()->user()))<span class="text-zinc-500">({{ __('you') }})</span>@endif</div>
                        <div class="truncate text-xs text-zinc-500">{{ $member->email }}</div>
                    </div>
                    @if ($memberRole === 'owner')
                        <flux:badge size="sm" color="emerald">{{ __('Owner') }}</flux:badge>
                    @elseif ($this->role === 'owner' && ! $member->is(auth()->user()))
                        <flux:select size="sm" class="!w-28" wire:change="changeRole({{ $member->id }}, $event.target.value)" data-test="member-role-{{ $member->id }}">
                            <flux:select.option value="member" :selected="$memberRole === 'member'">{{ __('Member') }}</flux:select.option>
                            <flux:select.option value="admin" :selected="$memberRole === 'admin'">{{ __('Admin') }}</flux:select.option>
                        </flux:select>
                    @else
                        <flux:badge size="sm">{{ $memberRole === 'admin' ? __('Admin') : __('Member') }}</flux:badge>
                    @endif

                    @if ($memberRole !== 'owner' && ! $member->is(auth()->user()) && ($this->role === 'owner' || ($this->role === 'admin' && $memberRole === 'member')))
                        <flux:dropdown align="end">
                            <flux:button size="sm" variant="subtle" icon="ellipsis-horizontal" />
                            <flux:menu>
                                @if ($this->role === 'owner')
                                    <flux:menu.item icon="key" wire:click="makeOwner({{ $member->id }})"
                                        wire:confirm="{{ __('Make :name the owner? You will become an admin.', ['name' => $member->name]) }}">{{ __('Make owner') }}</flux:menu.item>
                                @endif
                                <flux:menu.item icon="user-minus" variant="danger" wire:click="removeMember({{ $member->id }})"
                                    wire:confirm="{{ __('Remove :name from the team?', ['name' => $member->name]) }}">{{ __('Remove from team') }}</flux:menu.item>
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($this->isAdmin)
            <flux:separator />

            <form wire:submit="addMember" class="flex flex-col gap-2 sm:flex-row sm:items-start">
                <div class="flex-1">
                    <flux:input wire:model="memberEmail" type="email" :placeholder="__('Email of a Newsstand user')" data-test="member-email" />
                    <flux:error name="memberEmail" />
                </div>
                @if ($this->role === 'owner')
                    <flux:select wire:model="memberRole" class="sm:!w-32">
                        <flux:select.option value="member">{{ __('Member') }}</flux:select.option>
                        <flux:select.option value="admin">{{ __('Admin') }}</flux:select.option>
                    </flux:select>
                @endif
                <flux:button type="submit" variant="primary" data-test="member-add">{{ __('Add') }}</flux:button>
            </form>

            <div class="space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <flux:heading>{{ __('Invite links') }}</flux:heading>
                    <flux:spacer />
                    @if ($this->role === 'owner')
                        <flux:select size="sm" wire:model="inviteRole" class="!w-32">
                            <flux:select.option value="member">{{ __('Member') }}</flux:select.option>
                            <flux:select.option value="admin">{{ __('Admin') }}</flux:select.option>
                        </flux:select>
                    @endif
                    <flux:button size="sm" icon="link" wire:click="createInvite" data-test="invite-create">{{ __('New invite link') }}</flux:button>
                </div>
                <flux:text class="text-sm">{{ __('Anyone with a Newsstand account and the link can join, until it expires (:days days). Revoke a link to stop it working.', ['days' => \App\Services\Teams\TeamManager::INVITE_DAYS]) }}</flux:text>
                @foreach ($this->invitations as $invitation)
                    <div class="flex items-center gap-2" wire:key="invite-{{ $invitation->id }}">
                        <flux:input size="sm" readonly :value="$invitation->url()" copyable class="flex-1" data-test="invite-url" />
                        <flux:badge size="sm">{{ $invitation->role === 'admin' ? __('Admin') : __('Member') }}</flux:badge>
                        <span class="shrink-0 text-xs text-zinc-500">{{ __('expires :time', ['time' => $invitation->expires_at->diffForHumans()]) }}</span>
                        <flux:button size="sm" variant="subtle" icon="trash" wire:click="revokeInvite({{ $invitation->id }})" :tooltip="__('Revoke')" />
                    </div>
                @endforeach
            </div>
        @endif
    </flux:card>

    {{-- Settings --}}
    <flux:card class="space-y-4">
        <flux:heading size="lg">{{ __('Team settings') }}</flux:heading>

        @if ($this->isAdmin)
            <form wire:submit="rename" class="flex items-start gap-2">
                <div class="flex-1">
                    <flux:input wire:model="teamName" :label="__('Name')" />
                </div>
                <flux:button type="submit" class="mt-6">{{ __('Rename') }}</flux:button>
            </form>
        @endif

        <div class="flex flex-wrap gap-2">
            @if ($this->role !== 'owner')
                <flux:button variant="danger" icon="arrow-right-start-on-rectangle" wire:click="leave"
                    wire:confirm="{{ __('Leave :team? You lose access to its feeds, watchlist and notes.', ['team' => $team->name]) }}" data-test="leave-team">{{ __('Leave team') }}</flux:button>
            @else
                <flux:button variant="danger" icon="trash" wire:click="deleteTeam"
                    wire:confirm="{{ __('Delete :team? Its shared feeds, dashboards, watchlist, alert channels and notes are removed for everyone.', ['team' => $team->name]) }}" data-test="delete-team">{{ __('Delete team') }}</flux:button>
            @endif
        </div>
    </flux:card>
</div>
