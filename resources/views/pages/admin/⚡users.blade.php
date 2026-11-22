<?php

use App\Actions\Users\CreateUser;
use App\Models\User;
use Flux\Flux;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Users · Administration')] class extends Component {
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    // New user form
    public string $newEmail = '';
    public string $newName = '';
    public bool $newAdmin = false;

    /** Shown once after creating a user with a generated password. */
    public ?string $createdPassword = null;
    public ?string $createdEmail = null;

    public function mount(): void
    {
        $this->authorize('admin');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function users(): LengthAwarePaginator
    {
        return User::query()
            ->withCount(['subscriptions', 'dashboards'])
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q
                ->whereLike('name', '%'.trim($this->search).'%')
                ->orWhereLike('email', '%'.trim($this->search).'%')))
            ->orderByDesc('is_admin')
            ->orderBy('name')
            ->paginate(25);
    }

    public function create(CreateUser $create): void
    {
        $this->authorize('admin');
        $this->validate(['newEmail' => ['required', 'email'], 'newName' => ['nullable', 'string', 'max:255']]);

        try {
            [$user, $password] = $create->handle($this->newEmail, $this->newName, null, $this->newAdmin);
        } catch (\InvalidArgumentException $e) {
            $this->addError('newEmail', $e->getMessage());

            return;
        }

        $this->createdEmail = $user->email;
        $this->createdPassword = $password;
        $this->reset('newEmail', 'newName', 'newAdmin');
        unset($this->users);
    }

    public function toggleAdmin(int $id): void
    {
        $this->authorize('admin');
        $user = User::findOrFail($id);

        if ($user->isAdmin() && User::activeAdminCount() <= 1) {
            Flux::toast(variant: 'danger', text: __('That is the last administrator. Make someone else admin first.'));

            return;
        }

        $user->forceFill(['is_admin' => ! $user->isAdmin()])->save();
        unset($this->users);
    }

    public function toggleDisabled(int $id): void
    {
        $this->authorize('admin');
        $user = User::findOrFail($id);

        if ($user->is(Auth::user())) {
            Flux::toast(variant: 'danger', text: __('You can’t disable your own account.'));

            return;
        }

        if (! $user->isDisabled() && $user->isAdmin() && User::activeAdminCount() <= 1) {
            Flux::toast(variant: 'danger', text: __('That is the last administrator. Make someone else admin first.'));

            return;
        }

        $user->forceFill(['disabled_at' => $user->isDisabled() ? null : now()])->save();
        unset($this->users);
    }

    public function delete(int $id): void
    {
        $this->authorize('admin');
        $user = User::findOrFail($id);

        if ($user->is(Auth::user())) {
            Flux::toast(variant: 'danger', text: __('You can’t delete your own account here. Use Settings → Profile.'));

            return;
        }

        if ($user->isAdmin() && User::activeAdminCount() <= 1) {
            Flux::toast(variant: 'danger', text: __('That is the last administrator. Make someone else admin first.'));

            return;
        }

        $user->delete();
        unset($this->users);
        Flux::toast(text: __('Deleted :name.', ['name' => $user->name]));
    }
}; ?>

<x-pages::admin.layout :heading="__('Users')" :subheading="__('Create accounts, grant admin rights, or disable someone’s access.')">
    <div class="space-y-6">
        <flux:card class="space-y-4">
            <flux:heading>{{ __('Add a user') }}</flux:heading>
            <form wire:submit="create" class="flex flex-col gap-3 sm:flex-row sm:items-start">
                <div class="flex-1">
                    <flux:input wire:model="newEmail" type="email" :placeholder="__('Email address')" />
                    <flux:error name="newEmail" />
                </div>
                <flux:input wire:model="newName" :placeholder="__('Name (optional)')" class="sm:max-w-52" />
                <div class="pt-2"><flux:checkbox wire:model="newAdmin" :label="__('Admin')" /></div>
                <flux:button type="submit" variant="primary" data-test="create-user">{{ __('Create') }}</flux:button>
            </form>

            @if ($createdPassword)
                <flux:callout icon="key" variant="success" :heading="__('Created :email', ['email' => $createdEmail])">
                    <flux:callout.text>
                        {{ __('Temporary password (shown once):') }}
                        <code class="font-mono font-semibold select-all" data-test="created-password">{{ $createdPassword }}</code>
                        <br>{{ __('Ask them to change it under Settings → Security.') }}
                    </flux:callout.text>
                </flux:callout>
            @endif
        </flux:card>

        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search by name or email')" class="max-w-sm" clearable />

        <flux:table :paginate="$this->users">
            <flux:table.columns>
                <flux:table.column>{{ __('User') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('Feeds') }}</flux:table.column>
                <flux:table.column class="hidden md:table-cell">{{ __('Joined') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column></flux:table.column>
            </flux:table.columns>
            <flux:table.rows>
                @foreach ($this->users as $user)
                    <flux:table.row wire:key="user-{{ $user->id }}">
                        <flux:table.cell>
                            <div class="flex items-center gap-3">
                                <flux:avatar size="sm" :name="$user->name" :initials="$user->initials()" />
                                <div class="min-w-0">
                                    <div class="truncate font-medium">{{ $user->name }} @if ($user->is(auth()->user()))<span class="text-xs text-zinc-500">({{ __('you') }})</span>@endif</div>
                                    <div class="truncate text-xs text-zinc-500">{{ $user->email }}</div>
                                </div>
                            </div>
                        </flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">{{ $user->subscriptions_count }}</flux:table.cell>
                        <flux:table.cell class="hidden md:table-cell">{{ $user->created_at?->isoFormat('D MMM YYYY') }}</flux:table.cell>
                        <flux:table.cell>
                            <div class="flex flex-wrap gap-1">
                                @if ($user->isAdmin())<flux:badge size="sm" color="emerald" icon="shield-check">{{ __('Admin') }}</flux:badge>@endif
                                @if ($user->isDisabled())<flux:badge size="sm" color="red">{{ __('Disabled') }}</flux:badge>@endif
                                @if (! $user->email_verified_at)<flux:badge size="sm" color="amber">{{ __('Unverified') }}</flux:badge>@endif
                            </div>
                        </flux:table.cell>
                        <flux:table.cell align="end">
                            <flux:dropdown position="bottom" align="end">
                                <flux:button size="sm" variant="ghost" icon="ellipsis-horizontal" data-test="user-menu-{{ $user->id }}" />
                                <flux:menu>
                                    <flux:menu.item icon="shield-check" wire:click="toggleAdmin({{ $user->id }})">
                                        {{ $user->isAdmin() ? __('Remove admin rights') : __('Make admin') }}
                                    </flux:menu.item>
                                    @unless ($user->is(auth()->user()))
                                        <flux:menu.item :icon="$user->isDisabled() ? 'lock-open' : 'lock-closed'" wire:click="toggleDisabled({{ $user->id }})">
                                            {{ $user->isDisabled() ? __('Enable account') : __('Disable account') }}
                                        </flux:menu.item>
                                        <flux:menu.separator />
                                        <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $user->id }})"
                                            wire:confirm="{{ __('Delete :name and all their feeds, tags, rules and dashboards?', ['name' => $user->name]) }}">
                                            {{ __('Delete user') }}
                                        </flux:menu.item>
                                    @endunless
                                </flux:menu>
                            </flux:dropdown>
                        </flux:table.cell>
                    </flux:table.row>
                @endforeach
            </flux:table.rows>
        </flux:table>
    </div>
</x-pages::admin.layout>
