<?php

use App\Models\Setting;
use App\Support\Registration;
use Flux\Flux;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Settings · Administration')] class extends Component {
    public string $registration = 'closed';

    public bool $alertsAllowPrivate = false;

    public function mount(): void
    {
        $this->authorize('admin');
        $this->registration = Registration::mode();
        $this->alertsAllowPrivate = (bool) Setting::get('alerts.allow_private', (bool) config('newsstand.alerts.allow_private'));
    }

    public function save(): void
    {
        $this->authorize('admin');
        $this->validate(['registration' => ['required', 'in:'.implode(',', Registration::MODES)]]);

        Setting::set('registration', $this->registration);
        Setting::set('alerts.allow_private', $this->alertsAllowPrivate);
        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function useEnvironmentDefault(): void
    {
        $this->authorize('admin');
        Setting::forget('registration');
        $this->registration = Registration::mode();

        Flux::toast(text: __('Using the server default again.'));
    }
}; ?>

<x-pages::admin.layout :heading="__('Settings')" :subheading="__('Changes apply immediately and override the server’s .env defaults.')">
    <form wire:submit="save" class="max-w-xl space-y-6">
        <flux:radio.group wire:model="registration" :label="__('Who can create an account?')">
            <flux:radio value="open" :label="__('Anyone')" :description="__('The sign-up page is open to everyone who can reach this server.')" />
            <flux:radio value="closed" :label="__('Nobody, except the very first account')" :description="__('Lets a fresh install create its administrator, then closes.')" />
            <flux:radio value="disabled" :label="__('Nobody')" :description="__('Only administrators add users, under Users or with php artisan newsstand:user.')" data-test="registration-disabled" />
        </flux:radio.group>

        <flux:text size="sm">
            {{ __('Server default (NEWSSTAND_REGISTRATION): :mode', ['mode' => config('newsstand.registration')]) }}
            @if (\App\Models\Setting::get('registration') !== null)
                · <flux:link class="cursor-pointer" wire:click="useEnvironmentDefault">{{ __('use the server default') }}</flux:link>
            @endif
        </flux:text>

        <flux:separator />

        <flux:switch wire:model="alertsAllowPrivate" data-test="alerts-allow-private"
            :label="__('Allow alert channels on the local network')"
            :description="__('Needed for a self-hosted ntfy or Gotify on your LAN. Leave off if people you don’t fully trust have accounts: it lets them make the server send requests to internal addresses.')" />

        <flux:button type="submit" variant="primary" data-test="save-settings">{{ __('Save') }}</flux:button>
    </form>
</x-pages::admin.layout>
