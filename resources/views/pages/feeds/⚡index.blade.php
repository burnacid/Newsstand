<?php

use App\Actions\Feeds\SubscribeToFeed;
use App\Models\Folder;
use App\Models\Subscription;
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
        return Auth::user()->subscriptions()
            ->with(['feed' => fn ($q) => $q->withCount('entries')])
            ->get()
            ->sortBy(fn (Subscription $s) => mb_strtolower($s->displayTitle()))
            ->groupBy(fn (Subscription $s) => $s->folder_id ?? 0);
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
        $subscription->update([
            'custom_title' => $this->editTitle !== '' ? $this->editTitle : null,
            'folder_id' => $this->editFolderId !== '' ? (int) $this->editFolderId : null,
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

            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($this->folders as $folder)
                    <div class="flex items-center justify-between py-2" wire:key="folder-{{ $folder->id }}">
                        <span>{{ $folder->name }}</span>
                        <div class="flex gap-1">
                            <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="startRenameFolder({{ $folder->id }})" />
                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteFolder({{ $folder->id }})"
                                wire:confirm="{{ __('Delete this folder? Its feeds will move to Unfiled.') }}" />
                        </div>
                    </div>
                @empty
                    <flux:text>{{ __('No folders yet.') }}</flux:text>
                @endforelse
            </div>
        </flux:card>

        <flux:card class="space-y-4">
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

    @foreach (collect([0 => null])->union($this->folders->keyBy('id')) as $folderKey => $folder)
        @continue(! $groups->has($folderKey))

        <section class="space-y-2" wire:key="group-{{ $folderKey }}">
            <flux:heading size="lg">{{ $folder?->name ?? __('Unfiled') }}</flux:heading>

            <flux:table>
                <flux:table.columns>
                    <flux:table.column>{{ __('Feed') }}</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">{{ __('Entries') }}</flux:table.column>
                    <flux:table.column class="hidden md:table-cell">{{ __('Last fetched') }}</flux:table.column>
                    <flux:table.column>{{ __('Status') }}</flux:table.column>
                    <flux:table.column></flux:table.column>
                </flux:table.columns>

                <flux:table.rows>
                    @foreach ($groups[$folderKey] as $subscription)
                        @php($feed = $subscription->feed)
                        <flux:table.row wire:key="sub-{{ $subscription->id }}">
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
