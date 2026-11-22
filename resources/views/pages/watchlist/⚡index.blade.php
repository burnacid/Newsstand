<?php

use App\Models\Cve;
use App\Models\CveProduct;
use App\Models\Team;
use App\Models\User;
use App\Models\WatchlistItem;
use App\Services\Watchlist\WatchlistMatcher;
use App\Support\ProductName;
use Flux\Flux;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Watchlist')] class extends Component {
    public ?int $editingId = null;

    public string $vendor = '';

    public string $product = '';

    public string $aliases = '';

    /** '' for the personal watchlist, or a team id. */
    #[Url(as: 'team')]
    public string $teamId = '';

    /** Teams the user belongs to, for the switcher. */
    #[Computed]
    public function teams(): Collection
    {
        return Auth::user()->teams()->get();
    }

    #[Computed]
    public function team(): ?Team
    {
        return $this->teamId !== '' ? $this->teams->firstWhere('id', (int) $this->teamId) : null;
    }

    /** Personal list, or a team's list when one is picked (members read, admins edit). */
    #[Computed]
    public function canManage(): bool
    {
        return $this->team === null || $this->team->isAdmin(Auth::user());
    }

    /** @return HasMany<WatchlistItem, covariant User|Team> */
    private function scope(): HasMany
    {
        return $this->team ? $this->team->watchlist() : Auth::user()->watchlist();
    }

    private function authorizeManage(): void
    {
        abort_unless($this->canManage, 403);
    }

    public function updatedTeamId(): void
    {
        $this->cancelEdit();
        unset($this->team, $this->canManage, $this->items, $this->popular, $this->hasChannels);
    }

    #[Computed]
    public function items(): Collection
    {
        $items = $this->scope()->get();

        $hits = DB::table('watchlist_hits')
            ->join('entries', 'entries.id', '=', 'watchlist_hits.entry_id')
            ->whereIn('watchlist_hits.watchlist_item_id', $items->pluck('id'))
            ->where('entries.published_at', '>=', now()->subDays(30))
            ->groupBy('watchlist_hits.watchlist_item_id')
            ->selectRaw('watchlist_hits.watchlist_item_id as id, count(*) as stories')
            ->pluck('stories', 'id');

        return $items->each(function (WatchlistItem $item) use ($hits) {
            $cveIds = $item->cveProducts()->distinct()->pluck('cve_id');
            $item->setAttribute('stories', (int) ($hits[$item->id] ?? 0));
            $item->setAttribute('cves', $cveIds->count());
            $item->setAttribute('exploited', $cveIds->isEmpty() ? 0 : Cve::whereKey($cveIds)->where('in_kev', true)->count());
        });
    }

    /** Vendor names Newsstand knows (from CISA KEV and NVD), most common first. */
    #[Computed]
    public function vendorSuggestions(): Collection
    {
        return CveProduct::query()
            ->selectRaw('min(vendor) as vendor, vendor_key, count(*) as n')
            ->groupBy('vendor_key')
            ->orderByDesc('n')
            ->limit(300)
            ->pluck('vendor');
    }

    /** Products of the vendor being typed. */
    #[Computed]
    public function productSuggestions(): Collection
    {
        $key = ProductName::key($this->vendor);

        return $key === '' ? collect() : CveProduct::query()
            ->where('vendor_key', 'like', "%{$key}%")
            ->selectRaw('min(product) as product, product_key, count(*) as n')
            ->groupBy('product_key')
            ->orderByDesc('n')
            ->limit(100)
            ->pluck('product');
    }

    /** Popular vendors in the KEV catalog, for one-click adding. */
    #[Computed]
    public function popular(): Collection
    {
        $watched = $this->scope()->pluck('vendor_key')->all();

        return Cve::query()
            ->where('in_kev', true)->whereNotNull('kev_vendor')
            ->selectRaw('kev_vendor, count(*) as n')->groupBy('kev_vendor')->orderByDesc('n')->limit(20)
            ->pluck('kev_vendor')
            ->reject(fn (string $v) => in_array(ProductName::key($v), $watched, true))
            ->take(12)
            ->values();
    }

    public function save(WatchlistMatcher $matcher): void
    {
        $this->authorizeManage();

        $this->validate([
            'vendor' => ['required', 'string', 'max:100'],
            'product' => ['nullable', 'string', 'max:100'],
            'aliases' => ['nullable', 'string', 'max:500'],
        ]);

        $data = [
            'vendor' => trim($this->vendor),
            'product' => trim($this->product) ?: null,
            'aliases' => collect(explode(',', $this->aliases))->map(fn ($a) => trim($a))->filter(fn ($a) => mb_strlen($a) >= 2)->unique()->values()->all() ?: null,
        ];

        $duplicate = $this->scope()
            ->where('vendor_key', ProductName::key($data['vendor']))
            ->where('product_key', $data['product'] ? ProductName::key($data['product']) : null)
            ->when($this->editingId, fn ($q) => $q->whereKeyNot($this->editingId))
            ->exists();

        if ($duplicate) {
            $this->addError('vendor', __('That is already on this watchlist.'));

            return;
        }

        if ($this->editingId) {
            $item = $this->scope()->findOrFail($this->editingId);
            $item->update($data);
            // Matches were based on the old name; start over.
            DB::table('watchlist_hits')->where('watchlist_item_id', $item->id)->delete();
            Flux::modal('edit-item')->close();
        } else {
            $item = $this->scope()->create($data);
        }

        $found = $matcher->matchItem($item);

        $this->reset('editingId', 'vendor', 'product', 'aliases');
        unset($this->items, $this->popular);
        $this->dispatch('unread-changed');

        Flux::toast(variant: 'success', text: trans_choice('Watching :name. Found :count story from the last 30 days.|Watching :name. Found :count stories from the last 30 days.', $found, ['name' => $item->label()]));
    }

    public function quickAdd(string $vendor, WatchlistMatcher $matcher): void
    {
        $this->reset('editingId', 'product', 'aliases');
        $this->vendor = $vendor;
        $this->save($matcher);
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();

        $item = $this->scope()->findOrFail($id);

        $this->editingId = $item->id;
        $this->vendor = $item->vendor;
        $this->product = (string) $item->product;
        $this->aliases = implode(', ', $item->aliases ?? []);
        $this->resetErrorBag();

        Flux::modal('edit-item')->show();
    }

    public function cancelEdit(): void
    {
        $this->reset('editingId', 'vendor', 'product', 'aliases');
    }

    public function setAlertLevel(int $id, string $level): void
    {
        $this->authorizeManage();

        if (in_array($level, ['off', 'any', 'cve', 'kev'], true)) {
            $this->scope()->findOrFail($id)->update(['alert_level' => $level]);
            unset($this->items);
        }
    }

    #[Computed]
    public function hasChannels(): bool
    {
        return ($this->team ?? Auth::user())->alertChannels()->where('enabled', true)->exists();
    }

    public function remove(int $id): void
    {
        $this->authorizeManage();

        $this->scope()->findOrFail($id)->delete();
        unset($this->items, $this->popular);
        $this->dispatch('unread-changed');
    }
}; ?>

<div class="mx-auto w-full max-w-4xl space-y-8">
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <flux:heading size="xl" level="1">{{ __('Watchlist') }}</flux:heading>
            <flux:text class="mt-1 max-w-xl">{{ __('The vendors and products you run. Newsstand flags stories and vulnerabilities that affect them: through the CVEs they mention (NVD and CISA product data) or by name.') }}</flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if ($this->teams->isNotEmpty())
                <flux:select size="sm" class="!w-48" wire:model.live="teamId" data-test="watch-team">
                    <flux:select.option value="">{{ __('My watchlist') }}</flux:select.option>
                    @foreach ($this->teams as $t)
                        <flux:select.option value="{{ $t->id }}">{{ __('Team: :name', ['name' => $t->name]) }}</flux:select.option>
                    @endforeach
                </flux:select>
            @endif
            @if ($this->items->isNotEmpty())
                <flux:button icon="eye" :href="route('reader', ['s' => 'watchlist'])" wire:navigate>{{ __('Show matching stories') }}</flux:button>
            @endif
        </div>
    </div>

    @if ($this->team && ! $this->canManage)
        <flux:callout icon="user-group" :heading="__('Shared by :team', ['team' => $this->team->name])">
            <flux:callout.text>{{ __('Everyone in the team sees these matches. Team owners and admins manage the list.') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if ($this->canManage)

    <flux:card class="space-y-4">
        <flux:heading>{{ $this->team ? __('Add to the :team watchlist', ['team' => $this->team->name]) : __('Add to your watchlist') }}</flux:heading>
        <form wire:submit="save" class="grid gap-3 sm:grid-cols-[1fr_1fr_auto] sm:items-start">
            <div>
                <flux:input wire:model.live.debounce.400ms="vendor" :placeholder="__('Vendor, e.g. Fortinet')" list="watch-vendors" autocomplete="off" data-test="watch-vendor" />
                <flux:error name="vendor" />
            </div>
            <flux:input wire:model="product" :placeholder="__('Product (optional), e.g. FortiOS')" list="watch-products" autocomplete="off" data-test="watch-product" />
            <flux:button type="submit" variant="primary" data-test="watch-add">{{ __('Watch') }}</flux:button>
            <div class="sm:col-span-2">
                <flux:input wire:model="aliases" size="sm" :placeholder="__('Other names, comma separated (optional), e.g. FortiGate, FortiProxy')" />
            </div>
        </form>

        <datalist id="watch-vendors">
            @foreach ($this->vendorSuggestions as $suggestion)
                <option value="{{ $suggestion }}"></option>
            @endforeach
        </datalist>
        <datalist id="watch-products">
            @foreach ($this->productSuggestions as $suggestion)
                <option value="{{ $suggestion }}"></option>
            @endforeach
        </datalist>

        @if ($this->popular->isNotEmpty())
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="text-zinc-500">{{ __('Often exploited:') }}</span>
                @foreach ($this->popular as $vendor)
                    <button type="button" wire:click="quickAdd(@js($vendor))" class="rounded-full border border-zinc-200 px-2.5 py-0.5 hover:border-violet-400 hover:bg-violet-50 dark:border-zinc-700 dark:hover:bg-violet-950/40">
                        + {{ $vendor }}
                    </button>
                @endforeach
            </div>
        @endif
    </flux:card>
    @endif

    @if ($this->items->contains(fn ($i) => $i->alert_level !== 'off') && ! $this->hasChannels)
        <flux:callout icon="bell-slash" variant="warning" :heading="__('Alerts are on, but there’s nowhere to send them')">
            <flux:callout.text>
                <flux:link :href="route('alerts.edit', $this->team ? ['team' => $this->team->id] : [])" wire:navigate>{{ __('Add an alert channel') }}</flux:link>
                {{ __('(email, ntfy, Gotify, Slack, Teams or Discord).') }}
            </flux:callout.text>
        </flux:callout>
    @endif

    <div class="space-y-2">
        @forelse ($this->items as $item)
            <div class="flex items-center gap-4 rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800/60" wire:key="watch-{{ $item->id }}">
                <flux:icon.eye class="shrink-0 text-violet-600 dark:text-violet-400" />
                <div class="min-w-0 flex-1">
                    <div class="font-medium">{{ $item->vendor }} @if ($item->product)<span class="text-zinc-500">· {{ $item->product }}</span>@endif</div>
                    <div class="mt-0.5 flex flex-wrap gap-x-4 text-sm text-zinc-500">
                        <span>{{ trans_choice(':count story in 30 days|:count stories in 30 days', $item->stories) }}</span>
                        <span>{{ trans_choice(':count known CVE|:count known CVEs', $item->cves) }}</span>
                        @if ($item->exploited)<span class="text-red-600 dark:text-red-400">{{ trans_choice(':count actively exploited|:count actively exploited', $item->exploited) }}</span>@endif
                        @if ($item->aliases)<span>{{ __('also: :aliases', ['aliases' => implode(', ', $item->aliases)]) }}</span>@endif
                    </div>
                </div>
                @if ($this->canManage)
                <flux:select size="sm" class="!w-44 shrink-0" wire:change="setAlertLevel({{ $item->id }}, $event.target.value)" data-test="alert-level-{{ $item->id }}"
                    :tooltip="__('Alerts for this item')">
                    <flux:select.option value="off" :selected="$item->alert_level === 'off'">{{ __('No alerts') }}</flux:select.option>
                    <flux:select.option value="any" :selected="$item->alert_level === 'any'">{{ __('Alert: every story') }}</flux:select.option>
                    <flux:select.option value="cve" :selected="$item->alert_level === 'cve'">{{ __('Alert: stories with a CVE') }}</flux:select.option>
                    <flux:select.option value="kev" :selected="$item->alert_level === 'kev'">{{ __('Alert: actively exploited') }}</flux:select.option>
                </flux:select>
                <flux:button size="sm" variant="subtle" icon="pencil" wire:click="edit({{ $item->id }})" :tooltip="__('Edit')" />
                <flux:button size="sm" variant="subtle" icon="trash" wire:click="remove({{ $item->id }})" wire:confirm="{{ __('Stop watching :name?', ['name' => $item->label()]) }}" :tooltip="__('Remove')" />
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700">
                <flux:icon.eye class="mx-auto size-8 text-zinc-400" />
                <flux:heading class="mt-3">{{ __('Nothing watched yet') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Add the firewalls, VPNs, servers and software you run.') }}</flux:text>
            </div>
        @endforelse
    </div>

    <flux:modal name="edit-item" class="md:w-[28rem]" x-on:close="$wire.cancelEdit()">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ __('Edit watchlist item') }}</flux:heading>
            <flux:input wire:model="vendor" :label="__('Vendor')" list="watch-vendors" autocomplete="off" />
            <flux:error name="vendor" />
            <flux:input wire:model="product" :label="__('Product')" :placeholder="__('Optional')" list="watch-products" autocomplete="off" />
            <flux:input wire:model="aliases" :label="__('Other names')" :placeholder="__('Comma separated')" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
