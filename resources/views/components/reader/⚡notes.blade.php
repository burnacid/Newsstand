<?php

use App\Models\Entry;
use App\Models\EntryNote;
use App\Services\Teams\FeedAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * Notes under a story: private, or shared with one of the reader's teams.
 */
new class extends Component {
    #[Locked]
    public int $entryId;

    public string $body = '';

    /** '' = only me, otherwise a team id. */
    public string $visibility = '';

    public ?int $editingId = null;

    public string $editBody = '';

    public bool $open = false;

    public function mount(int $entryId): void
    {
        $this->entryId = $entryId;
        $this->open = $this->notes->isNotEmpty();

        // Default to the team the story reaches the reader through, if any.
        $teamId = FeedAccess::subscriptions(Auth::user())
            ->where('feed_id', Entry::whereKey($entryId)->value('feed_id'))
            ->whereNotNull('team_id')
            ->value('team_id');
        $this->visibility = $teamId ? (string) $teamId : '';
    }

    #[Computed]
    public function notes(): Collection
    {
        return EntryNote::query()
            ->visibleTo(Auth::user())
            ->where('entry_id', $this->entryId)
            ->with(['user:id,name', 'team:id,name'])
            ->oldest()
            ->get();
    }

    #[Computed]
    public function teams(): Collection
    {
        return Auth::user()->teams()->get();
    }

    /** Notes can be added by anyone who reads the story through their own or a team feed. */
    #[Computed]
    public function canWrite(): bool
    {
        $feedId = Entry::whereKey($this->entryId)->value('feed_id');

        return $feedId !== null && FeedAccess::canRead(Auth::user(), (int) $feedId);
    }

    public function add(): void
    {
        abort_unless($this->canWrite, 403);

        $this->validate([
            'body' => ['required', 'string', 'max:5000'],
            'visibility' => ['nullable', 'in:'.implode(',', ['', ...$this->teams->pluck('id')->all()])],
        ]);

        $note = new EntryNote([
            'entry_id' => $this->entryId,
            'team_id' => $this->visibility !== '' ? (int) $this->visibility : null,
            'body' => trim($this->body),
        ]);
        $note->user_id = Auth::id();
        $note->save();

        $this->reset('body');
        unset($this->notes);
    }

    public function edit(int $id): void
    {
        $note = $this->own($id);

        $this->editingId = $note->id;
        $this->editBody = $note->body;
    }

    public function update(): void
    {
        $this->validate(['editBody' => ['required', 'string', 'max:5000']]);

        $this->own((int) $this->editingId)->update(['body' => trim($this->editBody)]);

        $this->reset('editingId', 'editBody');
        unset($this->notes);
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'editBody');
    }

    public function delete(int $id): void
    {
        $note = $this->notes->firstWhere('id', $id);

        // Authors delete their own notes; team admins can tidy up the team's.
        abort_unless($note && ($note->user_id === Auth::id() || $note->team?->isAdmin(Auth::user())), 403);

        $note->delete();
        unset($this->notes);
    }

    private function own(int $id): EntryNote
    {
        return EntryNote::where('user_id', Auth::id())->findOrFail($id);
    }
}; ?>

<section class="mt-10 rounded-xl border border-zinc-200 bg-zinc-50/60 p-4 not-prose dark:border-zinc-700 dark:bg-zinc-800/40" data-test="notes">
    <button type="button" class="flex w-full items-center gap-2 text-start text-sm font-medium" wire:click="$toggle('open')">
        <flux:icon.chat-bubble-left-right variant="micro" class="text-zinc-400" />
        {{ $this->notes->isEmpty() ? __('Add a note') : trans_choice(':count note|:count notes', $this->notes->count()) }}
        <flux:spacer />
        <flux:icon :name="$open ? 'chevron-up' : 'chevron-down'" variant="micro" class="text-zinc-400" />
    </button>

    @if ($open)
        <div class="mt-3 space-y-3">
            @foreach ($this->notes as $note)
                <div class="rounded-lg bg-white p-3 text-sm dark:bg-zinc-900/60" wire:key="note-{{ $note->id }}">
                    <div class="flex items-center gap-2 text-xs text-zinc-500">
                        <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $note->user->name }}</span>
                        <span>{{ $note->created_at->diffForHumans() }}</span>
                        @if ($note->team)
                            <flux:badge size="sm" icon="user-group">{{ $note->team->name }}</flux:badge>
                        @else
                            <flux:badge size="sm" icon="lock-closed">{{ __('Only you') }}</flux:badge>
                        @endif
                        <flux:spacer />
                        @if ($note->user_id === auth()->id())
                            <flux:button size="xs" variant="subtle" icon="pencil" wire:click="edit({{ $note->id }})" :tooltip="__('Edit')" />
                        @endif
                        @if ($note->user_id === auth()->id() || $note->team?->isAdmin(auth()->user()))
                            <flux:button size="xs" variant="subtle" icon="trash" wire:click="delete({{ $note->id }})" wire:confirm="{{ __('Delete this note?') }}" :tooltip="__('Delete')" data-test="note-delete" />
                        @endif
                    </div>
                    @if ($editingId === $note->id)
                        <form wire:submit="update" class="mt-2 space-y-2">
                            <flux:textarea wire:model="editBody" rows="3" />
                            <flux:error name="editBody" />
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" variant="ghost" wire:click="cancelEdit">{{ __('Cancel') }}</flux:button>
                                <flux:button size="sm" type="submit" variant="primary">{{ __('Save') }}</flux:button>
                            </div>
                        </form>
                    @else
                        <p class="mt-1 whitespace-pre-line text-zinc-800 dark:text-zinc-200">{{ $note->body }}</p>
                    @endif
                </div>
            @endforeach

            @if ($this->canWrite)
                <form wire:submit="add" class="space-y-2">
                    <flux:textarea wire:model="body" rows="2" :placeholder="__('What should others know about this story?')" data-test="note-body" />
                    <flux:error name="body" />
                    <div class="flex items-center justify-end gap-2">
                        @if ($this->teams->isNotEmpty())
                            <flux:select size="sm" wire:model="visibility" class="!w-48" data-test="note-visibility">
                                <flux:select.option value="">{{ __('Only me') }}</flux:select.option>
                                @foreach ($this->teams as $team)
                                    <flux:select.option value="{{ $team->id }}">{{ __('Team: :name', ['name' => $team->name]) }}</flux:select.option>
                                @endforeach
                            </flux:select>
                        @endif
                        <flux:button size="sm" type="submit" variant="primary" data-test="note-add">{{ __('Add note') }}</flux:button>
                    </div>
                </form>
            @endif
        </div>
    @endif
</section>
