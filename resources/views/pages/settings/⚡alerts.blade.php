<?php

use App\Exceptions\UnsafeUrlException;
use App\Models\Alert;
use App\Models\AlertChannel;
use App\Models\PushSubscription;
use App\Models\Team;
use App\Models\User;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\PushService;
use App\Services\Alerts\Senders\WebhookSender;
use App\Support\UrlGuard;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Alerts')] class extends Component {
    /** Config fields that are secret: never shown again, kept when left empty on edit. */
    private const SECRETS = ['token', 'url'];

    public ?int $editingId = null;

    public string $type = 'ntfy';

    public string $name = '';

    /** @var array<string, string> */
    public array $fields = [];

    /** @var list<string> */
    public array $events = ['watchlist', 'kev'];

    /** '' for personal channels, or the id of a team the user manages. */
    #[Url(as: 'team')]
    public string $teamId = '';

    /** Teams whose channels this user may manage (owner or admin). */
    #[Computed]
    public function managedTeams(): Collection
    {
        return Auth::user()->managedTeams()->get();
    }

    #[Computed]
    public function team(): ?Team
    {
        return $this->teamId !== '' ? $this->managedTeams->firstWhere('id', (int) $this->teamId) : null;
    }

    private function owner(): User|Team
    {
        return $this->team ?? Auth::user();
    }

    public function updatedTeamId(): void
    {
        unset($this->team, $this->channels, $this->recent);
    }

    #[Computed]
    public function channels(): Collection
    {
        return $this->owner()->alertChannels()->withCount(['alerts as sent_count' => fn ($q) => $q->where('status', 'sent')])->get();
    }

    #[Computed]
    public function recent(): Collection
    {
        return Alert::query()
            ->whereIn('alert_channel_id', $this->owner()->alertChannels()->select('id'))
            ->with('channel:id,name,type')
            ->latest('id')
            ->limit(15)
            ->get();
    }

    // --- Push notifications on this user's devices ---

    /** The key browsers subscribe with; null when this server can't send push. */
    #[Computed]
    public function pushKey(): ?string
    {
        return app(PushService::class)->publicKey();
    }

    #[Computed]
    public function devices(): Collection
    {
        return Auth::user()->pushSubscriptions()->get();
    }

    /** Turn push on for the browser making this request. Returns its endpoint hash. */
    public function registerDevice(string $endpoint, string $publicKey, string $authToken): ?string
    {
        $validator = Validator::make(compact('endpoint', 'publicKey', 'authToken'), [
            'endpoint' => ['required', 'string', 'max:2000', 'url:https'],
            'publicKey' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+=*$/'],
            'authToken' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+=*$/'],
        ]);

        try {
            $validator->validate();
            PushService::assertSafeEndpoint($endpoint);
        } catch (\Throwable) {
            Flux::toast(variant: 'danger', text: __('This browser sent a push address Newsstand cannot use.'));

            return null;
        }

        $hash = PushSubscription::hashEndpoint($endpoint);

        // One browser has one subscription: if someone else signed in on it before, it moves to this user.
        PushSubscription::query()->firstOrNew(['endpoint_hash' => $hash])->forceFill([
            'user_id' => Auth::id(),
            'endpoint' => $endpoint,
            'public_key' => $publicKey,
            'auth_token' => $authToken,
            'label' => PushSubscription::labelFor(request()->userAgent()),
        ])->save();

        unset($this->devices, $this->channels);
        Flux::toast(variant: 'success', text: $this->channels->contains('type', 'push')
            ? __('Push notifications are on for this device.')
            : __('Push notifications are on for this device. Add a “Push notification” channel to choose what to get.'));

        return $hash;
    }

    /** Whether the server still has this browser; returns its endpoint hash. */
    public function knowsDevice(string $endpoint): ?string
    {
        $hash = PushSubscription::hashEndpoint($endpoint);

        return Auth::user()->pushSubscriptions()->where('endpoint_hash', $hash)->exists() ? $hash : null;
    }

    public function forgetDevice(string $endpoint): void
    {
        Auth::user()->pushSubscriptions()->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))->delete();
        unset($this->devices, $this->channels);
    }

    public function removeDevice(int $id): void
    {
        Auth::user()->pushSubscriptions()->whereKey($id)->delete();
        unset($this->devices, $this->channels);
    }

    public function create(): void
    {
        $this->reset('editingId', 'name', 'fields');
        $this->type = $this->pushKey ? 'push' : 'ntfy';
        $this->events = ['watchlist', 'kev'];
        $this->applyDefaults();
        $this->resetErrorBag();

        Flux::modal('channel')->show();
    }

    public function updatedType(): void
    {
        $this->fields = [];
        $this->applyDefaults();
    }

    public function edit(int $id): void
    {
        $channel = $this->find($id);

        $this->editingId = $channel->id;
        $this->type = $channel->type;
        $this->name = $channel->name;
        $this->events = $channel->events ?? [];
        // Secrets stay on the server; empty means "unchanged".
        $this->fields = collect($channel->config)->map(fn ($v, $k) => in_array($k, self::SECRETS, true) ? '' : (string) $v)->all();
        $this->resetErrorBag();

        Flux::modal('channel')->show();
    }

    public function save(): void
    {
        $this->validate([
            'type' => ['required', Rule::in(AlertChannel::TYPES)],
            'name' => ['required', 'string', 'max:100'],
            'events' => ['array'],
            'events.*' => [Rule::in(AlertChannel::EVENTS)],
        ]);

        $existing = $this->editingId ? $this->find($this->editingId) : null;
        $config = collect($this->fields)->map(fn ($v) => trim((string) $v));

        // Keep stored secrets that weren't retyped.
        if ($existing && $existing->type === $this->type) {
            foreach (self::SECRETS as $secret) {
                if ($config->get($secret, '') === '' && isset($existing->config[$secret])) {
                    $config[$secret] = $existing->config[$secret];
                }
            }
        }

        $config = $config->filter(fn ($v) => $v !== '')->all();
        $validator = Validator::make($config, AlertDispatcher::sender($this->type)->rules());

        if ($validator->fails()) {
            foreach ($validator->errors()->messages() as $field => $messages) {
                $this->addError("fields.{$field}", $messages[0]);
            }

            return;
        }

        foreach (['url', 'server'] as $field) {
            if (isset($config[$field])) {
                try {
                    UrlGuard::assertSafe($config[$field], WebhookSender::blocksPrivateAddresses());
                } catch (UnsafeUrlException $e) {
                    $this->addError("fields.{$field}", __('That address is not allowed: :reason (an administrator can allow private network addresses).', ['reason' => $e->getMessage()]));

                    return;
                }
            }
        }

        $data = ['type' => $this->type, 'name' => $this->name, 'config' => $config, 'events' => array_values($this->events)];

        $existing ? $existing->update([...$data, 'last_error' => null]) : $this->owner()->alertChannels()->create($data);

        Flux::modal('channel')->close();
        unset($this->channels);
        Flux::toast(variant: 'success', text: __('Saved. Use “Send test” to check it.'));
    }

    public function test(int $id, AlertDispatcher $alerts): void
    {
        $channel = $this->find($id);

        try {
            $alerts->sendNow($channel, AlertMessage::test());
            $channel->forceFill(['last_sent_at' => now(), 'last_error' => null])->save();
            Flux::toast(variant: 'success', text: __('Test sent to :name.', ['name' => $channel->name]));
        } catch (\Throwable $e) {
            $channel->forceFill(['last_error' => Str::limit($e->getMessage(), 500)])->save();
            Flux::toast(variant: 'danger', text: Str::limit($e->getMessage(), 200));
        }

        unset($this->channels);
    }

    public function toggle(int $id): void
    {
        $channel = $this->find($id);
        $channel->update(['enabled' => ! $channel->enabled]);
        unset($this->channels);
    }

    public function delete(int $id): void
    {
        $this->find($id)->delete();
        unset($this->channels, $this->recent);
    }

    private function find(int $id): AlertChannel
    {
        return $this->owner()->alertChannels()->findOrFail($id);
    }

    private function applyDefaults(): void
    {
        $this->fields = match ($this->type) {
            'push' => [],
            'email' => ['address' => Auth::user()->email],
            'ntfy' => ['server' => 'https://ntfy.sh', 'topic' => 'newsstand-'.Str::lower(Str::random(12)), 'token' => ''],
            'gotify' => ['url' => '', 'token' => ''],
            default => ['url' => ''],
        } + $this->fields;

        if ($this->name === '' || in_array($this->name, array_map(fn ($t) => AlertChannel::label($t), AlertChannel::TYPES), true)) {
            $this->name = AlertChannel::label($this->type);
        }
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading level="2" class="sr-only">{{ __('Alerts') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Alerts')" :subheading="__('Get told right away when something on your watchlist is in the news or gets exploited, or when a rule says so.')">
        <div class="space-y-6">
            <div class="flex flex-wrap items-center gap-2">
                <flux:button icon="plus" wire:click="create" data-test="add-channel">{{ __('Add a channel') }}</flux:button>
                @if ($this->managedTeams->isNotEmpty())
                    <flux:select size="sm" class="!w-56" wire:model.live="teamId" data-test="channel-team">
                        <flux:select.option value="">{{ __('My channels') }}</flux:select.option>
                        @foreach ($this->managedTeams as $t)
                            <flux:select.option value="{{ $t->id }}">{{ __('Team: :name', ['name' => $t->name]) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                @endif
            </div>
            @if ($this->team)
                <flux:text class="text-sm">{{ __('Channels of :team receive alerts for the team watchlist. Team owners and admins can also use them in their rules.', ['team' => $this->team->name]) }}</flux:text>
            @else
                {{-- Push on this browser. The device list itself comes from the server; the button needs the browser. --}}
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-test="push-devices"
                    x-data="pushDevice(@js($this->pushKey))">
                    <div class="flex flex-wrap items-start gap-3">
                        <flux:icon.device-phone-mobile class="mt-0.5 text-zinc-500" />
                        <div class="min-w-0 flex-1">
                            <div class="font-medium">{{ __('Push notifications on this device') }}</div>
                            @if (! $this->pushKey)
                                <flux:text size="sm" data-test="push-unavailable">{{ __('Not available on this server: PHP’s OpenSSL cannot create the keys push needs. On Windows, set OPENSSL_CONF to PHP’s extras/ssl/openssl.cnf.') }}</flux:text>
                            @else
                                <flux:text size="sm" x-show="!supported">{{ __('This browser can’t receive push notifications here. They need HTTPS, and on iPhone and iPad Newsstand must be added to the Home Screen first.') }}</flux:text>
                                <flux:text size="sm" x-show="supported && denied" x-cloak>{{ __('Notifications are blocked for this site in your browser settings.') }}</flux:text>
                                <flux:text size="sm" x-show="supported && !denied" x-cloak>{{ __('Alerts sent to a “Push notification” channel pop up on every device you turn this on for.') }}</flux:text>
                                <div class="mt-1 text-xs text-red-600 dark:text-red-400" x-show="error" x-text="error" x-cloak></div>
                            @endif
                        </div>
                        @if ($this->pushKey)
                            <div x-show="supported && !denied" x-cloak>
                                <flux:button size="sm" variant="primary" icon="bell" x-show="!enabled" x-on:click="enable()" x-bind:disabled="busy" data-test="push-enable">{{ __('Turn on') }}</flux:button>
                                <flux:button size="sm" icon="bell-slash" x-show="enabled" x-on:click="disable()" x-bind:disabled="busy" data-test="push-disable">{{ __('Turn off') }}</flux:button>
                            </div>
                        @endif
                    </div>

                    @if ($this->devices->isNotEmpty())
                        <div class="mt-3 space-y-1 border-t border-zinc-200 pt-3 dark:border-zinc-700">
                            @foreach ($this->devices as $device)
                                <div class="flex items-center gap-2 text-sm" wire:key="device-{{ $device->id }}" data-test="push-device">
                                    <span class="min-w-0 flex-1 truncate">
                                        {{ $device->label }}
                                        <flux:badge size="sm" color="emerald" x-show="currentHash === @js($device->endpoint_hash)" x-cloak>{{ __('This device') }}</flux:badge>
                                    </span>
                                    <span class="shrink-0 text-xs text-zinc-500">{{ $device->last_used_at ? __('last used :time', ['time' => $device->last_used_at->diffForHumans()]) : __('added :time', ['time' => $device->created_at->diffForHumans()]) }}</span>
                                    <flux:button size="xs" variant="subtle" icon="x-mark" :aria-label="__('Remove')" :title="__('Remove')"
                                        x-on:click="remove({{ $device->id }}, @js($device->endpoint_hash))" data-test="push-device-remove" />
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            <div class="space-y-2">
                @forelse ($this->channels as $channel)
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" wire:key="channel-{{ $channel->id }}">
                        <div class="flex items-start gap-3">
                            <flux:switch :checked="$channel->enabled" wire:click="toggle({{ $channel->id }})" class="mt-0.5" />
                            <div @class(['min-w-0 flex-1', 'opacity-50' => ! $channel->enabled])>
                                <div class="font-medium">{{ $channel->name }} <span class="text-sm font-normal text-zinc-500">· {{ \App\Models\AlertChannel::label($channel->type) }}</span></div>
                                <div class="truncate text-xs text-zinc-500">{{ $channel->destination() }}</div>
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @foreach ($channel->events ?? [] as $event)
                                        <flux:badge size="sm">{{ $event === 'kev' ? __('Exploited (KEV)') : __('Watchlist') }}</flux:badge>
                                    @endforeach
                                    <span class="text-xs text-zinc-500">{{ $channel->last_sent_at ? __('last sent :time', ['time' => $channel->last_sent_at->diffForHumans()]) : __('nothing sent yet') }}</span>
                                </div>
                                @if ($channel->last_error)
                                    <div class="mt-1 text-xs text-red-600 dark:text-red-400" data-test="channel-error">{{ $channel->last_error }}</div>
                                @endif
                            </div>
                            <flux:button size="sm" variant="ghost" icon="paper-airplane" wire:click="test({{ $channel->id }})" data-test="test-channel">{{ __('Send test') }}</flux:button>
                            <flux:button size="sm" variant="subtle" icon="pencil" wire:click="edit({{ $channel->id }})" :tooltip="__('Edit')" />
                            <flux:button size="sm" variant="subtle" icon="trash" wire:click="delete({{ $channel->id }})" wire:confirm="{{ __('Delete this channel?') }}" :tooltip="__('Delete')" />
                        </div>
                    </div>
                @empty
                    <flux:text>{{ __('No channels yet. Add one, then turn on alerts for items on your watchlist, or add a “Send alert” action to a rule.') }}</flux:text>
                @endforelse
            </div>

            @if ($this->recent->isNotEmpty())
                <div class="space-y-2">
                    <flux:heading>{{ __('Recent alerts') }}</flux:heading>
                    @foreach ($this->recent as $alert)
                        <div class="flex items-center gap-2 text-sm" wire:key="alert-{{ $alert->id }}">
                            <flux:badge size="sm" :color="match ($alert->status) { 'sent' => 'emerald', 'failed' => 'red', default => 'zinc' }">{{ $alert->status }}</flux:badge>
                            <span class="min-w-0 flex-1 truncate" title="{{ $alert->error }}">{{ $alert->payload['title'] ?? '' }}</span>
                            <span class="shrink-0 text-xs text-zinc-500">{{ $alert->channel->name }} · {{ $alert->created_at->diffForHumans() }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        <flux:modal name="channel" class="w-full md:w-[30rem]">
            <form wire:submit="save" class="space-y-5">
                <flux:heading size="lg">{{ $editingId ? __('Edit channel') : __('Add a channel') }}</flux:heading>

                <flux:select wire:model.live="type" :label="__('Send to')" :disabled="(bool) $editingId">
                    @foreach (\App\Models\AlertChannel::TYPES as $t)
                        <flux:select.option :value="$t">{{ \App\Models\AlertChannel::label($t) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="name" :label="__('Name')" />

                @switch($type)
                    @case('push')
                        <flux:callout icon="bell" variant="secondary" data-test="push-channel-note">
                            <flux:callout.text>
                                @if ($this->team)
                                    {{ __('Sends to the devices of everyone in :team who turned on push notifications (Settings → Alerts, on each device).', ['team' => $this->team->name]) }}
                                @else
                                    {{ __('Sends to every device you turned on push notifications for, above this list.') }}
                                @endif
                            </flux:callout.text>
                        </flux:callout>
                        @break
                    @case('email')
                        <flux:input wire:model="fields.address" type="email" :label="__('Email address')" />
                        <flux:error name="fields.address" />
                        @break
                    @case('ntfy')
                        <flux:input wire:model="fields.server" :label="__('Server')" :description="__('https://ntfy.sh or your own server.')" />
                        <flux:error name="fields.server" />
                        <flux:input wire:model="fields.topic" :label="__('Topic')" :description="__('Subscribe to this topic in the ntfy app. Use a hard-to-guess name on public servers.')" />
                        <flux:error name="fields.topic" />
                        <flux:input wire:model="fields.token" type="password" :label="__('Access token (optional)')" :placeholder="$editingId ? __('Unchanged') : ''" />
                        @break
                    @case('gotify')
                        <flux:input wire:model="fields.url" :label="__('Gotify server URL')" :placeholder="$editingId ? __('Unchanged') : 'https://gotify.example.com'" />
                        <flux:error name="fields.url" />
                        <flux:input wire:model="fields.token" type="password" :label="__('Application token')" :placeholder="$editingId ? __('Unchanged') : ''" />
                        <flux:error name="fields.token" />
                        @break
                    @default
                        <flux:input wire:model="fields.url" type="password" :label="__('Webhook URL')" :placeholder="$editingId ? __('Unchanged') : 'https://…'"
                            :description="match ($type) {
                                'slack' => __('Slack → Apps → Incoming Webhooks → Add New Webhook.'),
                                'teams' => __('Teams channel → Workflows → “Post to a channel when a webhook request is received”.'),
                                'discord' => __('Discord channel settings → Integrations → Webhooks → New Webhook.'),
                                default => '',
                            }" />
                        <flux:error name="fields.url" />
                @endswitch

                <div class="space-y-2">
                    <flux:text class="font-medium text-zinc-800 dark:text-zinc-200">{{ __('Send automatically') }}</flux:text>
                    <flux:checkbox wire:model="events" value="watchlist" :label="__('Stories about my watchlist')" :description="__('As set per item on the Watchlist page.')" />
                    <flux:checkbox wire:model="events" value="kev" :label="__('A watched product is added to CISA’s exploited list')" />
                    <flux:text size="sm">{{ __('Rules can also send to this channel with their “Send alert” action.') }}</flux:text>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close><flux:button variant="ghost">{{ __('Cancel') }}</flux:button></flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="save-channel">{{ __('Save') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    </x-pages::settings.layout>
</section>
