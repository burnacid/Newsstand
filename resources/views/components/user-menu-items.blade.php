{{-- Shared by the desktop (sidebar) and mobile (header) user menus. --}}
<flux:menu.radio.group>
    <flux:menu.item icon="eye" :href="route('watchlist')" wire:navigate>{{ __('Watchlist') }}</flux:menu.item>
    <flux:menu.item icon="adjustments-horizontal" :href="route('rules')" wire:navigate>{{ __('Rules') }}</flux:menu.item>
    <flux:menu.item icon="user-group" :href="route('teams.index')" wire:navigate data-test="teams-link">{{ __('Teams') }}</flux:menu.item>
    <flux:menu.item icon="rss" :href="route('feeds.index')" wire:navigate>{{ __('Manage feeds') }}</flux:menu.item>
    @can('admin')
        <flux:menu.item icon="shield-check" :href="route('admin.users')" wire:navigate data-test="admin-link">{{ __('Administration') }}</flux:menu.item>
    @endcan
</flux:menu.radio.group>

<flux:menu.separator />

<flux:menu.radio.group>
    <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
        {{ __('Settings') }}
    </flux:menu.item>
    {{-- Shown when the browser offers to install Newsstand (and it isn't running as the app already). --}}
    <flux:menu.item icon="arrow-down-tray" x-data x-show="$store.pwa?.canInstall && !$store.pwa?.standalone" x-cloak
        x-on:click="$store.pwa.install()" data-test="install-app">
        {{ __('Install app') }}
    </flux:menu.item>
</flux:menu.radio.group>

<flux:menu.separator />

<form method="POST" action="{{ route('logout') }}" class="w-full">
    @csrf
    <flux:menu.item
        as="button"
        type="submit"
        icon="arrow-right-start-on-rectangle"
        class="w-full cursor-pointer"
        data-test="logout-button"
    >
        {{ __('Log out') }}
    </flux:menu.item>
</form>
