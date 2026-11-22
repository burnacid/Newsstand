<?php

use App\Mail\DailyDigest;
use App\Services\Digest\DigestBuilder;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Daily digest')] class extends Component {
    public bool $digestEnabled = false;

    public int $digestHour = 7;

    public function mount(): void
    {
        $this->digestEnabled = (bool) Auth::user()->digest_enabled;
        $this->digestHour = (int) Auth::user()->digest_hour;
    }

    public function save(): void
    {
        $this->validate(['digestHour' => ['integer', 'between:0,23']]);

        Auth::user()->forceFill([
            'digest_enabled' => $this->digestEnabled,
            'digest_hour' => $this->digestHour,
        ])->save();

        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function sendTest(DigestBuilder $builder): void
    {
        $digest = $builder->for(Auth::user());

        if ($digest === null) {
            Flux::toast(text: __('Nothing to send: no top stories, exploited vulnerabilities or highlights in the last 24 hours.'));

            return;
        }

        Mail::to(Auth::user())->send(new DailyDigest(Auth::user(), $digest));
        Flux::toast(variant: 'success', text: __('Sent to :email.', ['email' => Auth::user()->email]));
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Daily digest') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Daily digest')" :subheading="__('A morning email with top stories, actively exploited vulnerabilities and stories your rules highlighted.')">
        <form wire:submit="save" class="space-y-6">
            <flux:switch wire:model="digestEnabled" :label="__('Send me a daily digest')" />

            <flux:select wire:model="digestHour" :label="__('Send at')" :description="__('Server time (:tz). Days without news are skipped.', ['tz' => config('app.timezone')])">
                @foreach (range(0, 23) as $hour)
                    <flux:select.option :value="$hour">{{ sprintf('%02d:00', $hour) }}</flux:select.option>
                @endforeach
            </flux:select>

            <div class="flex items-center gap-3">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button wire:click="sendTest" variant="ghost" icon="paper-airplane">{{ __('Send one now') }}</flux:button>
            </div>
        </form>
    </x-pages::settings.layout>
</section>
