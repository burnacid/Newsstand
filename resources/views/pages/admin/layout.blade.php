<div class="mx-auto w-full max-w-6xl">
    <div class="mb-6">
        <flux:heading size="xl" level="1">{{ __('Administration') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Newsstand :version', ['version' => 'v'.config('newsstand.version')]) }}</flux:text>
    </div>

    <div class="flex items-start max-md:flex-col">
        <div class="me-10 w-full pb-4 md:w-[200px]">
            <flux:navlist aria-label="{{ __('Administration') }}">
                <flux:navlist.item icon="users" :href="route('admin.users')" :current="request()->routeIs('admin.users')" wire:navigate>{{ __('Users') }}</flux:navlist.item>
                <flux:navlist.item icon="cog-6-tooth" :href="route('admin.settings')" :current="request()->routeIs('admin.settings')" wire:navigate>{{ __('Settings') }}</flux:navlist.item>
                <flux:navlist.item icon="heart" :href="route('admin.health')" :current="request()->routeIs('admin.health')" wire:navigate>{{ __('Health') }}</flux:navlist.item>
            </flux:navlist>
        </div>

        <flux:separator class="md:hidden" />

        <div class="min-w-0 flex-1 self-stretch max-md:pt-6">
            <flux:heading size="lg">{{ $heading ?? '' }}</flux:heading>
            @if (! empty($subheading))
                <flux:subheading>{{ $subheading }}</flux:subheading>
            @endif

            <div class="mt-5 w-full">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
