<?php

use App\Models\TeamInvitation;
use App\Services\Teams\TeamManager;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * Landing page of an invite link: shows the team and lets a signed-in user join.
 */
new #[Title('Join team')] class extends Component {
    #[Locked]
    public string $token = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        $team = $this->invitation()?->team;

        // Already in the team: nothing to accept.
        if ($team && $team->isMember(Auth::user())) {
            $this->redirectRoute('teams.show', $team, navigate: true);
        }
    }

    public function invitation(): ?TeamInvitation
    {
        return TeamInvitation::with('team')->where('token', $this->token)->first();
    }

    public function join(TeamManager $teams): void
    {
        $team = $teams->accept($this->token, Auth::user());

        if (! $team) {
            $this->addError('token', __('This invite link is no longer valid.'));

            return;
        }

        $this->redirectRoute('teams.show', $team, navigate: true);
    }
}; ?>

<div class="mx-auto w-full max-w-lg py-10">
    @php($invitation = $this->invitation())

    <flux:card class="space-y-5 text-center">
        <flux:icon.user-group class="mx-auto size-10 text-emerald-700 dark:text-emerald-400" />

        @if ($invitation && ! $invitation->isExpired())
            <div>
                <flux:heading size="xl" level="1">{{ __('Join :team', ['team' => $invitation->team->name]) }}</flux:heading>
                <flux:text class="mt-2">
                    {{ $invitation->role === 'admin'
                        ? __('You are invited as an admin: you will see the team’s feeds, watchlist and notes, and help manage them.')
                        : __('You will see the team’s feeds, watchlist, dashboards and notes. Your read state stays your own.') }}
                </flux:text>
            </div>
            <flux:error name="token" />
            <flux:button variant="primary" wire:click="join" data-test="join-team">{{ __('Join team') }}</flux:button>
        @else
            <div>
                <flux:heading size="xl" level="1">{{ __('Invite link not valid') }}</flux:heading>
                <flux:text class="mt-2">{{ __('This invite link has expired or was revoked. Ask a team admin for a new one.') }}</flux:text>
            </div>
            <flux:button :href="route('teams.index')" wire:navigate>{{ __('Go to your teams') }}</flux:button>
        @endif
    </flux:card>
</div>
