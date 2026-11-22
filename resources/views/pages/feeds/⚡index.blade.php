<?php

use App\Actions\Feeds\Organizer;
use App\Actions\Feeds\SubscribeToFeed;
use App\Actions\Reader\TagEntries;
use App\Models\Folder;
use App\Models\Subscription;
use App\Models\Tag;
use App\Services\Feeds\FeedDiscovery;
use App\Services\Feeds\FeedRefresher;
use App\Services\Feeds\OpmlService;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Title('Feeds')] class extends Component {
    use WithFileUploads;

    // Subscribe form
    public string $url = '';
    public string $folderId = '';

    /** @var list<array{url: string, title: string|null}> */
    public array $candidates = [];

    // OPML
    public $opml;

    // Folder form
    public string $folderName = '';

    // Edit subscription
    public ?int $editingId = null;
    public string $editTitle = '';
    public string $editFolderId = '';
    public bool $editFullText = false;

    // Rename folder
    public ?int $renamingFolderId = null;
    public string $renameFolderName = '';

    #[Computed]
    public function folders(): Collection
    {
        return Auth::user()->folders()->get();
    }

    #[Computed]
    public function subscriptions(): Collection
    {
        return Organizer::sortSubscriptions(
            Auth::user()->subscriptions()->with(['feed' => fn ($q) => $q->withCount('entries')])->get()
        )->groupBy(fn (Subscription $s) => $s->folder_id ?? 0);
    }

    #[Computed]
    public function tags(): Collection
    {
        return Auth::user()->tags()->withCount('entries')->get();
    }

    // --- Drag and drop ---

    public function sortFolder(int $id, int $position, Organizer $organizer): void
    {
        $organizer->moveFolder(Auth::user(), $id, $position);
        unset($this->folders);
    }

    /** $group is the target folder id, or "0" for Unfiled. */
    public function sortSubscription(int $id, int $position, string $group, Organizer $organizer): void
    {
        $organizer->moveSubscription(Auth::user(), $id, $position, (int) $group ?: null);
        unset($this->subscriptions);
    }

    // --- Tags ---

    public string $newTagName = '';

    public function createTag(TagEntries $tags): void
    {
        $this->validate(['newTagName' => ['required', 'string', 'max:50']]);
        $tags->create(Auth::user(), $this->newTagName);

        $this->reset('newTagName');
        unset($this->tags);
    }

    public function renameTag(int $id, string $name): void
    {
        $name = trim($name);
        $tag = Auth::user()->tags()->findOrFail($id);

        if ($name === '' || mb_strlen($name) > 50 || Auth::user()->tags()->where('name', $name)->whereKeyNot($id)->exists()) {
            Flux::toast(variant: 'danger', text: __('That tag name is empty, too long or already used.'));

            return;
        }

        $tag->update(['name' => $name]);
        unset($this->tags);
    }

    public function recolorTag(int $id, string $color): void
    {
        if (in_array($color, Tag::COLORS, true)) {
            Auth::user()->tags()->findOrFail($id)->update(['color' => $color]);
            unset($this->tags);
        }
    }

    public function deleteTag(int $id): void
    {
        Auth::user()->tags()->findOrFail($id)->delete();
        unset($this->tags);
    }

    public function discover(FeedDiscovery $discovery, SubscribeToFeed $subscribe): void
    {
        $this->validate(['url' => ['required', 'string', 'max:2048'], 'folderId' => $this->folderRule()]);
        $this->candidates = [];

        try {
            $found = $discovery->discover($this->url);
        } catch (\Throwable $e) {
            $this->addError('url', __('Could not load that URL: :message', ['message' => $e->getMessage()]));

            return;
        }

        if ($found === []) {
            $this->addError('url', __('No RSS or Atom feed found at that address.'));

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
        $candidate = $this->candidates[$index] ?? null;

        if ($candidate) {
            $this->subscribeTo($subscribe, $candidate['url'], $candidate['title']);
        }
    }

    private function subscribeTo(SubscribeToFeed $subscribe, string $url, ?string $title): void
    {
        $folder = $this->folderId ? Auth::user()->folders()->find($this->folderId) : null;
        $subscription = $subscribe->handle(Auth::user(), $url, $folder, $title);

        $this->reset('url', 'candidates');
        unset($this->subscriptions);

        Flux::toast(variant: 'success', text: __('Subscribed to :title.', ['title' => $subscription->displayTitle()]));
    }

    public function refresh(int $id, FeedRefresher $refresher): void
    {
        $subscription = $this->findSubscription($id);

        $refresher->refresh($subscription->feed);
        unset($this->subscriptions);

        $subscription->feed->last_error
            ? Flux::toast(variant: 'danger', text: $subscription->feed->last_error)
            : Flux::toast(variant: 'success', text: __('Feed refreshed.'));
    }

    public function unsubscribe(int $id): void
    {
        $this->findSubscription($id)->delete();
        unset($this->subscriptions);

        Flux::toast(text: __('Unsubscribed.'));
    }

    public function edit(int $id): void
    {
        $subscription = $this->findSubscription($id);

        $this->editingId = $subscription->id;
        $this->editTitle = $subscription->custom_title ?? '';
        $this->editFolderId = (string) ($subscription->folder_id ?? '');
        $this->editFullText = $subscription->feed->full_text;

        Flux::modal('edit-subscription')->show();
    }

    public function saveEdit(): void
    {
        $this->validate([
            'editTitle' => ['nullable', 'string', 'max:250'],
            'editFolderId' => $this->folderRule(),
        ]);

        $subscription = $this->findSubscription($this->editingId);
        $folderId = $this->editFolderId !== '' ? (int) $this->editFolderId : null;

        $subscription->update([
            'custom_title' => $this->editTitle !== '' ? $this->editTitle : null,
            'folder_id' => $folderId,
            // Moving to another folder puts the feed at the end of it.
            'position' => $subscription->folder_id === $folderId
                ? $subscription->position
                : SubscribeToFeed::nextPosition(Auth::user(), $folderId),
        ]);

        // Full-text extraction is a property of the (shared) feed.
        $subscription->feed->update(['full_text' => $this->editFullText]);

        $this->reset('editingId', 'editTitle', 'editFolderId', 'editFullText');
        unset($this->subscriptions);
        Flux::modal('edit-subscription')->close();
    }

    public function createFolder(): void
    {
        $this->validate(['folderName' => [
            'required', 'string', 'max:100',
            Rule::unique('folders', 'name')->where('user_id', Auth::id()),
        ]]);

        Auth::user()->folders()->create([
            'name' => $this->folderName,
            'position' => Auth::user()->folders()->max('position') + 1,
        ]);

        $this->reset('folderName');
        unset($this->folders);
    }

    public function startRenameFolder(int $id): void
    {
        $folder = $this->findFolder($id);

        $this->renamingFolderId = $folder->id;
        $this->renameFolderName = $folder->name;

        Flux::modal('rename-folder')->show();
    }

    public function renameFolder(): void
    {
        $folder = $this->findFolder($this->renamingFolderId);

        $this->validate(['renameFolderName' => [
            'required', 'string', 'max:100',
            Rule::unique('folders', 'name')->where('user_id', Auth::id())->ignore($folder->id),
        ]]);

        $folder->update(['name' => $this->renameFolderName]);

        $this->reset('renamingFolderId', 'renameFolderName');
        unset($this->folders);
        Flux::modal('rename-folder')->close();
    }

    public function deleteFolder(int $id): void
    {
        // Subscriptions in the folder move to "Unfiled" (FK is nullOnDelete).
        $this->findFolder($id)->delete();
        unset($this->folders, $this->subscriptions);
    }

    public function importOpml(OpmlService $opml): void
    {
        $this->validate(['opml' => ['required', 'file', 'max:2048']]);

        try {
            $stats = $opml->import(Auth::user(), file_get_contents($this->opml->getRealPath()));
        } catch (\InvalidArgumentException $e) {
            $this->addError('opml', $e->getMessage());

            return;
        }

        $this->reset('opml');
        unset($this->folders, $this->subscriptions);

        Flux::toast(variant: 'success', text: __('Imported :n feeds into :f new folders.', ['n' => $stats['subscribed'], 'f' => $stats['folders']]));
    }

    private function findSubscription(?int $id): Subscription
    {
        return Auth::user()->subscriptions()->with('feed')->findOrFail($id);
    }

    private function findFolder(?int $id): Folder
    {
        return Auth::user()->folders()->findOrFail($id);
    }

    private function folderRule(): array
    {
        return ['nullable', Rule::exists('folders', 'id')->where('user_id', Auth::id())];
    }
}; ?>

<div class="mx-auto w-full max-w-5xl space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Feeds') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Subscribe to sites, organize them in folders and keep an eye on feed health.') }}</flux:text>
    </div>

    {{-- Subscribe --}}
    <flux:card class="space-y-4">
        <flux:heading size="lg">{{ __('Add a feed') }}</flux:heading>

        <form wire:submit="discover" class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <div class="flex-1">
                <flux:input wire:model="url" type="text" :placeholder="__('Site or feed URL, e.g. krebsonsecurity.com')" icon="link" />
                <flux:error name="url" />
            </div>

            <flux:select wire:model="folderId" class="sm:max-w-48">
                <flux:select.option value="">{{ __('No folder') }}</flux:select.option>
                @foreach ($this->folders as $folder)
                    <flux:select.option value="{{ $folder->id }}">{{ $folder->name }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:button type="submit" variant="primary" data-test="subscribe-button">
                <span wire:loading.remove wire:target="discover">{{ __('Subscribe') }}</span>
                <span wire:loading wire:target="discover">{{ __('Looking…') }}</span>
            </flux:button>
        </form>

        @if ($candidates)
            <div class="space-y-2">
                <flux:text>{{ __('This site has several feeds. Pick one:') }}</flux:text>
                @foreach ($candidates as $i => $candidate)
                    <div class="flex items-center justify-between gap-3 rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700" wire:key="candidate-{{ $i }}">
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ $candidate['title'] ?? __('Untitled feed') }}</div>
                            <div class="truncate text-sm text-zinc-500">{{ $candidate['url'] }}</div>
                        </div>
                        <flux:button size="sm" wire:click="choose({{ $i }})">{{ __('Subscribe') }}</flux:button>
                    </div>
                @endforeach
            </div>
        @endif
    </flux:card>

    {{-- Folders + OPML --}}
    <div class="grid gap-6 md:grid-cols-2">
        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('Folders') }}</flux:heading>

            <form wire:submit="createFolder" class="flex gap-2">
                <div class="flex-1">
                    <flux:input wire:model="folderName" :placeholder="__('New folder name')" />
                    <flux:error name="folderName" />
                </div>
                <flux:button type="submit">{{ __('Add') }}</flux:button>
            </form>

            @if ($this->folders->count() > 1)
                <flux:text size="sm">{{ __('Drag to reorder. The sidebar follows this order.') }}</flux:text>
            @endif

            <div class="-mx-2 space-y-0.5" wire:sort="sortFolder" data-test="folder-list">
                @forelse ($this->folders as $folder)
                    <div class="list-row" wire:key="folder-{{ $folder->id }}" wire:sort:item="{{ $folder->id }}">
                        <span class="flex min-w-0 items-center gap-2.5">
                            <span class="list-row-handle" wire:sort:handle title="{{ __('Drag to reorder') }}">
                                <flux:icon.bars-2 variant="micro" />
                            </span>
                            <flux:icon.folder variant="micro" class="shrink-0 text-zinc-400 dark:text-zinc-500" />
                            <span class="truncate">{{ $folder->name }}</span>
                            <span class="text-xs text-zinc-400 dark:text-zinc-500">{{ $this->subscriptions->get($folder->id)?->count() ?? 0 }}</span>
                        </span>
                        <span class="list-row-actions">
                            <flux:button size="xs" variant="subtle" icon="pencil" :tooltip="__('Rename')" wire:click="startRenameFolder({{ $folder->id }})" />
                            <flux:button size="xs" variant="subtle" icon="trash" :tooltip="__('Delete')" wire:click="deleteFolder({{ $folder->id }})"
                                wire:confirm="{{ __('Delete this folder? Its feeds will move to Unfiled.') }}" />
                        </span>
                    </div>
                @empty
                    <flux:text>{{ __('No folders yet.') }}</flux:text>
                @endforelse
            </div>
        </flux:card>

        <flux:card class="space-y-4">
            <flux:heading size="lg">{{ __('Tags') }}</flux:heading>

            <form wire:submit="createTag" class="flex gap-2">
                <div class="flex-1">
                    <flux:input wire:model="newTagName" :placeholder="__('New tag name')" />
                    <flux:error name="newTagName" />
                </div>
                <flux:button type="submit">{{ __('Add') }}</flux:button>
            </form>

            <div class="-mx-2 space-y-0.5">
                @forelse ($this->tags as $tag)
                    <div class="list-row" wire:key="tag-{{ $tag->id }}"
                        x-data="{ editing: false, name: @js($tag->name) }">
                        <span class="flex min-w-0 flex-1 items-center gap-2.5">
                            <flux:dropdown>
                                <button type="button" @class(['size-3 shrink-0 rounded-full transition hover:scale-125', "bg-{$tag->color}-500"])
                                    title="{{ __('Change color') }}"></button>
                                <flux:menu>
                                    <div class="grid grid-cols-5 gap-2 p-2">
                                        @foreach (\App\Models\Tag::COLORS as $color)
                                            <button type="button" wire:click="recolorTag({{ $tag->id }}, '{{ $color }}')"
                                                @class(['size-6 rounded-full', "bg-{$color}-500", 'ring-2 ring-offset-2 ring-zinc-400' => $tag->color === $color])
                                                title="{{ $color }}"></button>
                                        @endforeach
                                    </div>
                                </flux:menu>
                            </flux:dropdown>

                            <span x-show="! editing" class="truncate">{{ $tag->name }}</span>
                            <input x-show="editing" x-cloak x-model="name" x-ref="input"
                                class="min-w-0 flex-1 rounded-md border border-zinc-300 bg-white px-2 py-0.5 text-sm outline-none focus:ring-2 focus:ring-accent dark:border-zinc-600 dark:bg-zinc-800"
                                x-on:keydown.enter="$wire.renameTag({{ $tag->id }}, name); editing = false"
                                x-on:keydown.escape="editing = false; name = @js($tag->name)"
                                x-on:blur="editing = false; name = @js($tag->name)">
                            <span x-show="! editing" class="text-xs text-zinc-400 dark:text-zinc-500">{{ $tag->entries_count }}</span>
                        </span>
                        <span class="list-row-actions">
                            <flux:button size="xs" variant="subtle" icon="pencil" :tooltip="__('Rename')"
                                x-on:mousedown.prevent x-on:click="editing = ! editing; $nextTick(() => $refs.input.focus())" />
                            <flux:button size="xs" variant="subtle" icon="trash" :tooltip="__('Delete')" wire:click="deleteTag({{ $tag->id }})"
                                wire:confirm="{{ __('Delete this tag? Stories keep their other tags.') }}" />
                        </span>
                    </div>
                @empty
                    <flux:text>{{ __('No tags yet. Tag a story from the reader with the tag button or by pressing t.') }}</flux:text>
                @endforelse
            </div>
        </flux:card>

        <flux:card class="space-y-4 md:col-span-2">
            <flux:heading size="lg">{{ __('Import / export') }}</flux:heading>
            <flux:text>{{ __('Move your subscriptions between readers with an OPML file.') }}</flux:text>

            <form wire:submit="importOpml" class="space-y-3">
                <flux:input type="file" wire:model="opml" accept=".opml,.xml,text/xml,text/x-opml" />
                <flux:error name="opml" />
                <div class="flex gap-2">
                    <flux:button type="submit" icon="arrow-up-tray">{{ __('Import OPML') }}</flux:button>
                    <flux:button :href="route('feeds.export')" icon="arrow-down-tray" variant="ghost">{{ __('Export OPML') }}</flux:button>
                </div>
            </form>
        </flux:card>
    </div>

    {{-- Subscriptions --}}
    @php($groups = $this->subscriptions)

    @if ($groups->isEmpty())
        <flux:callout icon="rss" :heading="__('No subscriptions yet')" :text="__('Add a site above or import an OPML file to get started.')" />
    @endif

    @if ($groups->isNotEmpty())
        <flux:text size="sm" class="flex items-center gap-1.5">
            <flux:icon.bars-2 variant="micro" />
            {{ __('Drag feeds to reorder them or to move them to another folder.') }}
        </flux:text>
    @endif

    @foreach (collect([0 => null])->union($this->folders->keyBy('id')) as $folderKey => $folder)
        {{-- Every folder is a drop target, even when empty; Unfiled only when there are feeds at all. --}}
        @continue($groups->isEmpty() || ($folderKey === 0 && ! $groups->has(0) && $this->folders->isEmpty()))

        <section class="space-y-2" wire:key="group-{{ $folderKey }}">
            <flux:heading size="lg">{{ $folder?->name ?? __('Unfiled') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column class="w-6"></flux:table.column>
                    <flux:table.column>{{ __('Feed') }}</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">{{ __('Entries') }}</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">{{ __('Last fetched') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows wire:sort="sortSubscription" wire:sort:group="subscriptions" wire:sort:group-id="{{ $folderKey }}" data-test="feeds-{{ $folderKey }}">
                    @if (! $groups->has($folderKey))
                        <tr class="text-sm text-zinc-400" wire:key="empty-{{ $folderKey }}">
                            <td colspan="6" class="py-4 text-center italic">{{ __('Drop feeds here') }}</td>
                        </tr>
                    @endif
                    @foreach ($groups[$folderKey] ?? [] as $subscription)
                        @php($feed = $subscription->feed)
                        <flux:table.row wire:key="sub-{{ $subscription->id }}" wire:sort:item="{{ $subscription->id }}">
                            <flux:table.cell class="w-6 !pe-0">
                                <flux:icon.bars-2 variant="micro" class="cursor-grab text-zinc-400" wire:sort:handle />
                            </flux:table.cell>
                            <flux:table.cell class="max-w-xs">
                                <div class="truncate font-medium">{{ $subscription->displayTitle() }}</div>
                                <div class="truncate text-xs text-zinc-500">{{ $feed->url }}</div>
                            </flux:table.cell>
                            <flux:table.cell class="hidden md:table-cell">{{ number_format($feed->entries_count) }}</flux:table.cell>
                            <flux:table.cell class="hidden md:table-cell">
                                {{ $feed->last_fetched_at?->diffForHumans() ?? __('Pending') }}
                            </flux:table.cell>
                            <flux:table.cell>
                                @if ($feed->last_fetched_at === null)
                                    <flux:badge size="sm" color="zinc">{{ __('Queued') }}</flux:badge>
                                @elseif ($feed->isHealthy())
                                    <flux:badge size="sm" color="green">{{ __('OK') }}</flux:badge>
                                @else
                                    <flux:tooltip :content="$feed->last_error">
                                        <flux:badge size="sm" color="red">{{ trans_choice(':count error|:count errors', $feed->error_count) }}</flux:badge>
                                    </flux:tooltip>
                                @endif
                            </flux:table.cell>
                            <flux:table.cell align="end">
                                <flux:dropdown position="bottom" align="end">
                                    <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" />
                                    <flux:menu>
                                        <flux:menu.item icon="arrow-path" wire:click="refresh({{ $subscription->id }})">{{ __('Refresh now') }}</flux:menu.item>
                                        <flux:menu.item icon="pencil-square" wire:click="edit({{ $subscription->id }})">{{ __('Edit') }}</flux:menu.item>
                                        @if ($feed->site_url)
                                            <flux:menu.item icon="arrow-top-right-on-square" :href="$feed->site_url" target="_blank">{{ __('Visit site') }}</flux:menu.item>
                                        @endif
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger" wire:click="unsubscribe({{ $subscription->id }})"
                                            wire:confirm="{{ __('Unsubscribe from this feed?') }}">{{ __('Unsubscribe') }}</flux:menu.item>
                                    </flux:menu>
                                </flux:dropdown>
                            </flux:table.cell>
                        </flux:table.row>
                    @endforeach
                </flux:table.rows>
            </flux:table>
        </section>
    @endforeach

    {{-- Modals --}}
    <flux:modal name="edit-subscription" class="md:w-96">
        <form wire:submit="saveEdit" class="space-y-5">
            <flux:heading size="lg">{{ __('Edit subscription') }}</flux:heading>
            <flux:input wire:model="editTitle" :label="__('Custom title')" :placeholder="__('Leave empty to use the feed title')" />
            <flux:select wire:model="editFolderId" :label="__('Folder')">
                <flux:select.option value="">{{ __('No folder') }}</flux:select.option>
                @foreach ($this->folders as $folder)
                    <flux:select.option value="{{ $folder->id }}">{{ $folder->name }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:switch wire:model="editFullText" :label="__('Fetch full article text')"
                :description="__('For feeds that only publish a summary. Applies to everyone subscribed to this feed.')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="rename-folder" class="md:w-96">
        <form wire:submit="renameFolder" class="space-y-5">
            <flux:heading size="lg">{{ __('Rename folder') }}</flux:heading>
            <flux:input wire:model="renameFolderName" :label="__('Name')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
