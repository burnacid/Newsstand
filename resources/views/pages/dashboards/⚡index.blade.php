<?php

use App\Models\Dashboard;
use App\Services\Teams\FeedAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboards')] class extends Component {
    public string $name = '';

    public string $template = 'security';

    /** Team to attach the new dashboard to ('' = personal). */
    public string $teamId = '';

    /** Teams you own or administer: only they can have a new team dashboard. */
    #[Computed]
    public function teams(): Collection
    {
        return Auth::user()->teams()->wherePivotIn('role', ['owner', 'admin'])->get(['teams.id', 'teams.name']);
    }

    /** Your personal dashboards. */
    #[Computed]
    public function owned(): Collection
    {
        return Auth::user()->dashboards()->whereNull('team_id')->withCount(['widgets', 'members'])->get();
    }

    /** Dashboards of the teams you are in. */
    #[Computed]
    public function team(): Collection
    {
        $user = Auth::user();

        return Dashboard::query()
            ->whereIn('team_id', FeedAccess::teamIds($user))
            ->with('team:id,name')
            ->withCount('widgets')
            ->get()
            ->sortBy([fn ($a, $b) => strcasecmp($a->team->name, $b->team->name), fn ($a, $b) => strcasecmp($a->name, $b->name)])
            ->each(fn (Dashboard $d) => $d->setAttribute('viewer_role', $d->roleOf($user)))
            ->values();
    }

    /** Shared with you directly (and not already listed under one of your teams). */
    #[Computed]
    public function shared(): Collection
    {
        $user = Auth::user();

        return Dashboard::query()
            ->whereIn('id', DB::table('dashboard_user')->select('dashboard_id')->where('user_id', $user->id))
            ->where(fn ($q) => $q->whereNull('team_id')->orWhereNotIn('team_id', FeedAccess::teamIds($user)))
            ->with(['owner:id,name', 'team:id,name'])
            ->withCount('widgets')
            ->orderBy('name')
            ->get()
            ->each(fn (Dashboard $d) => $d->setAttribute('viewer_role', $d->roleOf($user)));
    }

    public function create(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'template' => ['in:security,blank'],
            'teamId' => ['nullable', Rule::in($this->teams->pluck('id')->map(fn ($id) => (string) $id)->all())],
        ], [], ['teamId' => __('team')]);

        $dashboard = Auth::user()->dashboards()->create([
            'name' => $this->name,
            'team_id' => $this->teamId === '' ? null : (int) $this->teamId,
        ]);

        if ($this->template === 'security') {
            // A sensible starting point; edit freely afterwards.
            $widgets = [
                ['type' => 'top', 'title' => __('Top stories'), 'config' => ['limit' => 8], 'size' => 'wide'],
                ['type' => 'cves', 'title' => __('Actively exploited'), 'config' => ['filter' => 'kev', 'limit' => 6], 'size' => 'normal'],
                ['type' => 'cves', 'title' => __('Critical vulnerabilities'), 'config' => ['filter' => 'critical', 'limit' => 6], 'size' => 'normal'],
                ['type' => 'search', 'title' => __('Ransomware'), 'config' => ['query' => 'ransomware', 'limit' => 6], 'size' => 'normal'],
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
            @if ($this->teams->isNotEmpty())
                <div class="sm:max-w-48">
                    <flux:select wire:model="teamId" data-test="dashboard-team">
                        <flux:select.option value="">{{ __('Personal') }}</flux:select.option>
                        @foreach ($this->teams as $team)
                            <flux:select.option value="{{ $team->id }}">{{ __('Team :team', ['team' => $team->name]) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="teamId" />
                </div>
            @endif
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
                @if ($dashboard->id === auth()->user()->default_dashboard_id)
                    <flux:badge size="sm" icon="star" color="amber">{{ __('Default') }}</flux:badge>
                @endif
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

    @if ($this->team->isNotEmpty())
        <section class="space-y-3" data-test="team-dashboards">
            <flux:heading size="lg">{{ __('Team dashboards') }}</flux:heading>
            @foreach ($this->team as $dashboard)
                <a href="{{ route('dashboards.show', $dashboard) }}" wire:navigate wire:key="team-{{ $dashboard->id }}"
                    class="flex items-center gap-4 rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-emerald-500 dark:border-zinc-700 dark:bg-zinc-800/60">
                    <flux:icon.user-group class="text-emerald-700 dark:text-emerald-400" />
                    <div class="min-w-0 flex-1">
                        <div class="font-serif text-lg font-semibold">{{ $dashboard->name }}</div>
                        <div class="text-sm text-zinc-500">{{ __('Team :team', ['team' => $dashboard->team->name]) }} · {{ trans_choice(':count widget|:count widgets', $dashboard->widgets_count) }}</div>
                    </div>
                    @if ($dashboard->id === auth()->user()->default_dashboard_id)
                        <flux:badge size="sm" icon="star" color="amber">{{ __('Default') }}</flux:badge>
                    @endif
                    @if ($dashboard->share_token)
                        <flux:badge size="sm" icon="globe-alt" color="emerald">{{ __('Public link') }}</flux:badge>
                    @endif
                    <flux:badge size="sm">{{ match ($dashboard->viewer_role) { 'owner' => __('You manage'), 'editor' => __('Can edit'), default => __('Can view') } }}</flux:badge>
                </a>
            @endforeach
        </section>
    @endif

    @if ($this->shared->isNotEmpty())
        <section class="space-y-3">
            <flux:heading size="lg">{{ __('Shared with you') }}</flux:heading>
            @foreach ($this->shared as $dashboard)
                <a href="{{ route('dashboards.show', $dashboard) }}" wire:navigate wire:key="shared-{{ $dashboard->id }}"
                    class="flex items-center gap-4 rounded-xl border border-zinc-200 bg-white p-4 transition hover:border-emerald-500 dark:border-zinc-700 dark:bg-zinc-800/60">
                    <flux:icon.users class="text-zinc-500" />
                    <div class="min-w-0 flex-1">
                        <div class="font-serif text-lg font-semibold">{{ $dashboard->name }}</div>
                        <div class="text-sm text-zinc-500">{{ $dashboard->team ? __('team :team', ['team' => $dashboard->team->name]) : __('by :name', ['name' => $dashboard->curatorName()]) }} · {{ trans_choice(':count widget|:count widgets', $dashboard->widgets_count) }}</div>
                    </div>
                    @if ($dashboard->id === auth()->user()->default_dashboard_id)
                        <flux:badge size="sm" icon="star" color="amber">{{ __('Default') }}</flux:badge>
                    @endif
                    <flux:badge size="sm">{{ $dashboard->viewer_role === 'editor' ? __('Can edit') : __('Can view') }}</flux:badge>
                </a>
            @endforeach
        </section>
    @endif
</div>
