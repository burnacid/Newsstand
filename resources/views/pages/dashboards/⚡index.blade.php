<?php

use App\Models\Dashboard;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboards')] class extends Component {
    public string $name = '';

    public string $template = 'security';

    #[Computed]
    public function owned(): Collection
    {
        return Auth::user()->dashboards()->withCount(['widgets', 'members'])->get();
    }

    #[Computed]
    public function shared(): Collection
    {
        return Auth::user()->sharedDashboards()->with('owner:id,name')->withCount('widgets')->get();
    }

    public function create(): void
    {
        $this->validate(['name' => ['required', 'string', 'max:100'], 'template' => ['in:security,blank']]);

        $dashboard = Auth::user()->dashboards()->create(['name' => $this->name]);

        if ($this->template === 'security') {
            // A sensible starting point; edit freely afterwards.
            $widgets = [
                ['type' => 'top', 'title' => __('Top stories'), 'config' => ['limit' => 8], 'wide' => true],
                ['type' => 'cves', 'title' => __('Actively exploited'), 'config' => ['filter' => 'kev', 'limit' => 6], 'wide' => false],
                ['type' => 'cves', 'title' => __('Critical vulnerabilities'), 'config' => ['filter' => 'critical', 'limit' => 6], 'wide' => false],
                ['type' => 'search', 'title' => __('Ransomware'), 'config' => ['query' => 'ransomware', 'limit' => 6], 'wide' => false],
            ];

            foreach ($widgets as $position => $widget) {
                $dashboard->widgets()->create([...$widget, 'position' => $position]);
            }
        }

        $this->redirectRoute('dashboards.show', $dashboard, navigate: true);
    }
}; ?>

<div class="mx-auto w-full max-w-5xl space-y-8">
    <div>
        <flux:heading size="xl" level="1">{{ __('Dashboards') }}</flux:heading>
        <flux:text class="mt-1 max-w-2xl">{{ __('Pages of widgets built from your feeds, folders, tags and searches. Share them with other Newsstand users, or publish a read-only link.') }}</flux:text>
    </div>

    <flux:card class="space-y-4">
        <flux:heading size="lg">{{ __('New dashboard') }}</flux:heading>
        <form wire:submit="create" class="flex flex-col gap-3 sm:flex-row sm:items-start">
            <div class="flex-1">
                <flux:input wire:model="name" :placeholder="__('e.g. Security briefing')" />
                <flux:error name="name" />
            </div>
            <flux:select wire:model="template" class="sm:max-w-56">
                <flux:select.option value="security">{{ __('Start from security briefing') }}</flux:select.option>
                <flux:select.option value="blank">{{ __('Start empty') }}</flux:select.option>
            </flux:select>
            <flux:button type="submit" variant="primary" data-test="create-dashboard">{{ __('Create') }}</flux:button>
        </form>
    </flux:card>

    <section class="space-y-3">
        <flux:heading size="lg">{{ __('Your dashboards') }}</flux:heading>
        @forelse ($this->owned as $dashboard)
            <a href="{{ route('dashboards.show', $dashboard) }}" wire:navigate wire:key="own-{{ $dashboard->id }}"
                class="flex items-center gap-4 rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-emerald-500 dark:border-zinc-700 dark:bg-zinc-800/60">
                <flux:icon.squares-2x2 class="text-emerald-700 dark:text-emerald-400" />
                <div class="min-w-0 flex-1">
                    <div class="font-serif text-lg font-semibold">{{ $dashboard->name }}</div>
                    <div class="text-sm text-zinc-500">{{ trans_choice(':count widget|:count widgets', $dashboard->widgets_count) }}</div>
                </div>
                @if ($dashboard->members_count)
                    <flux:badge size="sm" icon="users">{{ trans_choice('Shared with :count|Shared with :count', $dashboard->members_count) }}</flux:badge>
                @endif
                @if ($dashboard->share_token)
                    <flux:badge size="sm" icon="globe-alt" color="emerald">{{ __('Public link') }}</flux:badge>
                @endif
            </a>
        @empty
            <flux:text>{{ __('You have no dashboards yet.') }}</flux:text>
        @endforelse
    </section>

    @if ($this->shared->isNotEmpty())
        <section class="space-y-3">
            <flux:heading size="lg">{{ __('Shared with you') }}</flux:heading>
            @foreach ($this->shared as $dashboard)
                <a href="{{ route('dashboards.show', $dashboard) }}" wire:navigate wire:key="shared-{{ $dashboard->id }}"
                    class="flex items-center gap-4 rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-emerald-500 dark:border-zinc-700 dark:bg-zinc-800/60">
                    <flux:icon.users class="text-zinc-500" />
                    <div class="min-w-0 flex-1">
                        <div class="font-serif text-lg font-semibold">{{ $dashboard->name }}</div>
                        <div class="text-sm text-zinc-500">{{ __('by :name', ['name' => $dashboard->owner->name]) }} · {{ trans_choice(':count widget|:count widgets', $dashboard->widgets_count) }}</div>
                    </div>
                    <flux:badge size="sm">{{ $dashboard->pivot->role === 'editor' ? __('Can edit') : __('Can view') }}</flux:badge>
                </a>
            @endforeach
        </section>
    @endif
</div>
