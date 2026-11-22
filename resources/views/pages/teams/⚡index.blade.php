<?php

use App\Services\Teams\TeamManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Teams')] class extends Component {
    public string $name = '';

    #[Computed]
    public function teams(): Collection
    {
        return Auth::user()->teams()->withCount(['members', 'subscriptions', 'watchlist'])->get();
    }

    public function create(TeamManager $teams): void
    {
        $this->validate(['name' => ['required', 'string', 'max:100']]);

        $team = $teams->create(Auth::user(), $this->name);

        $this->redirectRoute('teams.show', $team, navigate: true);
    }
}; ?>

<div class="mx-auto w-full max-w-4xl space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Teams') }}</flux:heading>
        <flux:text class="mt-1 max-w-2xl">{{ __('Share feeds, a watchlist, alert channels, dashboards and notes on stories with the people you work with. Everyone keeps their own read state.') }}</flux:text>
    </div>

    <flux:card class="space-y-4">
        <flux:heading size="lg">{{ __('New team') }}</flux:heading>
        <form wire:submit="create" class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <div class="flex-1">
                <flux:input wire:model="name" :placeholder="__('e.g. Security operations')" data-test="team-name" />
                <flux:error name="name" />
            </div>
            <flux:button type="submit" variant="primary" data-test="create-team">{{ __('Create team') }}</flux:button>
        </form>
    </flux:card>

    <section class="space-y-3">
        <flux:heading size="lg">{{ __('Your teams') }}</flux:heading>
        @forelse ($this->teams as $team)
            <a href="{{ route('teams.show', $team) }}" wire:navigate wire:key="team-{{ $team->id }}"
                class="flex items-center gap-4 rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-emerald-500 dark:border-zinc-700 dark:bg-zinc-800/60">
                <flux:icon.user-group class="text-emerald-700 dark:text-emerald-400" />
                <div class="min-w-0 flex-1">
                    <div class="font-serif text-lg font-semibold">{{ $team->name }}</div>
                    <div class="text-sm text-zinc-500">
                        {{ trans_choice(':count member|:count members', $team->members_count) }}
                        · {{ trans_choice(':count shared feed|:count shared feeds', $team->subscriptions_count) }}
                        · {{ trans_choice(':count watched product|:count watched products', $team->watchlist_count) }}
                    </div>
                </div>
                <flux:badge size="sm">{{ ucfirst($team->pivot->role) }}</flux:badge>
            </a>
        @empty
            <flux:text>{{ __('You are not in a team yet. Create one, or ask a team admin for an invite link.') }}</flux:text>
        @endforelse
    </section>
</div>
