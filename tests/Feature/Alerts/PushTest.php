<?php

use App\Models\PushSubscription;
use App\Models\Setting;
use App\Models\Team;
use App\Models\User;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Alerts\AlertMessage;
use App\Services\Alerts\PushService;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Livewire\Livewire;

/** Records what would be sent and answers with the given status per endpoint (default 201). */
class FakePushService extends PushService
{
    /** @var list<array{endpoint: string, payload: array<string, mixed>, options: array<string, mixed>}> */
    public array $sent = [];

    /** @param array<string, int> $statuses */
    public function __construct(public array $statuses = [], public bool $isAvailable = true)
    {
        parent::__construct();
    }

    public function available(): bool
    {
        return $this->isAvailable;
    }

    protected function deliver(Collection $subscriptions, string $payload, array $options): array
    {
        return $subscriptions->map(function (PushSubscription $subscription) use ($payload, $options) {
            $this->sent[] = ['endpoint' => $subscription->endpoint, 'payload' => json_decode($payload, true), 'options' => $options];
            $status = $this->statuses[$subscription->endpoint] ?? 201;

            return ['subscription' => $subscription, 'ok' => $status < 300, 'expired' => in_array($status, [404, 410], true), 'reason' => "HTTP {$status}"];
        })->all();
    }
}

function fakePush(array $statuses = [], bool $available = true): FakePushService
{
    $fake = new FakePushService($statuses, $available);
    app()->instance(PushService::class, $fake);

    return $fake;
}

function device(User $user, string $endpoint = 'https://8.8.8.8/push/a'): PushSubscription
{
    return PushSubscription::query()->forceCreate([
        'user_id' => $user->id, 'endpoint' => $endpoint, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
        'public_key' => 'BPublicKey', 'auth_token' => 'authToken', 'label' => 'Edge on Windows',
    ]);
}

beforeEach(function () {
    config(['newsstand.alerts.push.public_key' => 'BTestPublicKey', 'newsstand.alerts.push.private_key' => 'test-private-key']);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('registers this browser for push from the alerts page', function () {
    fakePush();

    Livewire::test('pages::settings.alerts')
        ->call('registerDevice', 'https://8.8.8.8/push/abc', 'BKey_-1', 'auth-Token')
        ->assertReturned(PushSubscription::hashEndpoint('https://8.8.8.8/push/abc'));

    $device = $this->user->pushSubscriptions()->sole();
    expect($device->endpoint)->toBe('https://8.8.8.8/push/abc')
        ->and($device->public_key)->toBe('BKey_-1')
        ->and($device->label)->not->toBe('');
});

it('names devices after their browser and system', function (string $userAgent, string $label) {
    expect(PushSubscription::labelFor($userAgent))->toBe($label);
})->with([
    ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36 Edg/140.0', 'Edge on Windows'],
    ['Mozilla/5.0 (Linux; Android 15) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Mobile Safari/537.36', 'Chrome on Android'],
    ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1', 'Safari on iPhone'],
    ['Mozilla/5.0 (X11; Linux x86_64; rv:140.0) Gecko/20100101 Firefox/140.0', 'Firefox on Linux'],
    ['', 'Browser'],
]);

it('moves a browser to whoever signs in on it and turns push on', function () {
    fakePush();
    $other = User::factory()->create();
    device($other, 'https://8.8.8.8/push/shared');

    Livewire::test('pages::settings.alerts')->call('registerDevice', 'https://8.8.8.8/push/shared', 'BKey', 'auth');

    expect(PushSubscription::query()->sole()->user_id)->toBe($this->user->id);
});

it('refuses push addresses that are not public https', function (string $endpoint) {
    fakePush();

    Livewire::test('pages::settings.alerts')
        ->call('registerDevice', $endpoint, 'BKey', 'auth')
        ->assertReturned(null);

    expect(PushSubscription::query()->count())->toBe(0);
})->with([
    'plain http' => 'http://8.8.8.8/push/a',
    'private network' => 'https://10.0.0.5/push/a',
    'loopback' => 'https://127.0.0.1/push/a',
    'not a url' => 'javascript:alert(1)',
]);

it('only lets users see and remove their own devices', function () {
    fakePush();
    $mine = device($this->user, 'https://8.8.8.8/push/mine');
    $theirs = device(User::factory()->create(), 'https://8.8.8.8/push/theirs');

    Livewire::test('pages::settings.alerts')
        ->assertSee('Edge on Windows')
        ->call('knowsDevice', 'https://8.8.8.8/push/theirs')->assertReturned(null)
        ->call('knowsDevice', 'https://8.8.8.8/push/mine')->assertReturned($mine->endpoint_hash)
        ->call('removeDevice', $theirs->id)
        ->call('forgetDevice', 'https://8.8.8.8/push/theirs');

    expect($theirs->fresh())->not->toBeNull();

    Livewire::test('pages::settings.alerts')->call('removeDevice', $mine->id);
    expect($mine->fresh())->toBeNull();
});

it('says so on the alerts page when this server cannot send push', function () {
    fakePush(available: false);

    Livewire::test('pages::settings.alerts')
        ->assertSee('Not available on this server')
        ->assertDontSee('data-test="push-enable"', false);
});

it('sends a push channel to the owner’s devices only', function () {
    $push = fakePush();
    device($this->user, 'https://8.8.8.8/push/1');
    device($this->user, 'https://8.8.8.8/push/2');
    device(User::factory()->create(), 'https://8.8.8.8/push/other');
    $channel = $this->user->alertChannels()->create(['type' => 'push', 'name' => 'Phone', 'config' => [], 'events' => ['watchlist']]);

    app(AlertDispatcher::class)->sendNow($channel, new AlertMessage('CVE-2026-1234 exploited', 'Patch now.', 'https://news.example.com/a', 'critical', 'Watchlist'));

    expect(collect($push->sent)->pluck('endpoint')->sort()->values()->all())->toBe(['https://8.8.8.8/push/1', 'https://8.8.8.8/push/2'])
        ->and($push->sent[0]['payload'])->toMatchArray([
            'title' => 'CVE-2026-1234 exploited',
            'body' => "Watchlist\nPatch now.",
            'url' => 'https://news.example.com/a',
            'level' => 'critical',
        ])
        ->and($push->sent[0]['options']['urgency'])->toBe('high')
        ->and($this->user->pushSubscriptions()->whereNotNull('last_used_at')->count())->toBe(2)
        ->and($channel->destination())->toBe('2 devices');
});

it('sends a team push channel to every member’s devices', function () {
    $push = fakePush();
    $team = Team::query()->forceCreate(['name' => 'Blue team']);
    $member = User::factory()->create();
    $team->members()->attach([$this->user->id => ['role' => 'owner'], $member->id => ['role' => 'member']]);
    device($this->user, 'https://8.8.8.8/push/owner');
    device($member, 'https://8.8.8.8/push/member');
    device(User::factory()->create(), 'https://8.8.8.8/push/outsider');
    $channel = $team->alertChannels()->create(['type' => 'push', 'name' => 'Team push', 'config' => [], 'events' => ['watchlist']]);

    app(AlertDispatcher::class)->sendNow($channel, AlertMessage::test());

    expect(collect($push->sent)->pluck('endpoint')->sort()->values()->all())->toBe(['https://8.8.8.8/push/member', 'https://8.8.8.8/push/owner']);
});

it('removes devices the push service says are gone', function () {
    fakePush(['https://8.8.8.8/push/gone' => 410]);
    device($this->user, 'https://8.8.8.8/push/ok');
    device($this->user, 'https://8.8.8.8/push/gone');
    $channel = $this->user->alertChannels()->create(['type' => 'push', 'name' => 'Push', 'config' => []]);

    app(AlertDispatcher::class)->sendNow($channel, AlertMessage::test());

    expect($this->user->pushSubscriptions()->pluck('endpoint')->all())->toBe(['https://8.8.8.8/push/ok']);
});

it('fails with a reason when no device takes the push', function () {
    fakePush(['https://8.8.8.8/push/a' => 403]);
    $channel = $this->user->alertChannels()->create(['type' => 'push', 'name' => 'Push', 'config' => []]);

    expect(fn () => app(AlertDispatcher::class)->sendNow($channel, AlertMessage::test()))
        ->toThrow(RuntimeException::class, 'No devices get push notifications yet');

    device($this->user);
    expect(fn () => app(AlertDispatcher::class)->sendNow($channel, AlertMessage::test()))
        ->toThrow(RuntimeException::class, 'HTTP 403');

    fakePush(available: false);
    expect(fn () => app(AlertDispatcher::class)->sendNow($channel, AlertMessage::test()))
        ->toThrow(RuntimeException::class, 'not available on this server');
});

it('saves a push channel without settings', function () {
    fakePush();

    Livewire::test('pages::settings.alerts')
        ->call('create')
        ->assertSet('type', 'push')
        ->assertSee('Sends to every device you turned on push notifications for')
        ->call('save')
        ->assertHasNoErrors();

    expect($this->user->alertChannels()->sole())->type->toBe('push')->name->toBe('Push notification');
});

it('generates the key pair once and keeps the private key encrypted', function () {
    config(['newsstand.alerts.push.public_key' => null, 'newsstand.alerts.push.private_key' => null]);
    device($this->user, 'https://8.8.8.8/push/old-pair');

    $keys = app(PushService::class)->keys();

    expect(app(PushService::class)->keys())->toBe($keys)
        ->and(Setting::get('alerts.push_keys')['public'])->toBe($keys['publicKey'])
        ->and(Crypt::decryptString(Setting::get('alerts.push_keys')['private']))->toBe($keys['privateKey'])
        ->and(Setting::get('alerts.push_keys')['private'])->not->toContain($keys['privateKey'])
        // Devices subscribed for an earlier pair can't be reached with the new one.
        ->and(PushSubscription::query()->count())->toBe(0);
})->skip(fn () => ! PushService::supported(), 'PHP’s OpenSSL cannot create EC keys here (set OPENSSL_CONF on Windows).');

it('encrypts and posts to the push service with VAPID', function () {
    config(['newsstand.alerts.push.public_key' => null, 'newsstand.alerts.push.private_key' => null]);
    $service = new PushService;
    $service->keys();

    // A browser's subscription keys: an uncompressed P-256 public key and a 16-byte auth secret.
    $b64 = fn (string $bytes) => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    $browser = function (string $endpoint) use ($b64) {
        $key = openssl_pkey_get_details(openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]))['ec'];
        device($this->user, $endpoint)->forceFill(['public_key' => $b64("\x04".$key['x'].$key['y']), 'auth_token' => $b64(random_bytes(16))])->save();
    };
    $browser('https://8.8.8.8/push/real');
    $browser('https://8.8.8.8/push/expired');
    // Broken keys: removed without a request, and without stopping the others.
    device($this->user, 'https://8.8.8.8/push/broken');

    $history = [];
    $stack = HandlerStack::create(fn ($request) => Create::promiseFor(new Response(str_contains((string) $request->getUri(), 'expired') ? 410 : 201)));
    $stack->push(Middleware::history($history));
    $result = (new PushService(new Client(['handler' => $stack])))->send($this->user->pushSubscriptions()->oldest('id')->get(), AlertMessage::test());

    expect($result['sent'])->toBe(1)
        ->and($result['errors'])->toHaveCount(2)
        ->and($history)->toHaveCount(2)
        ->and($history[0]['request']->getHeaderLine('Content-Encoding'))->toBe('aes128gcm')
        ->and($history[0]['request']->getHeaderLine('Authorization'))->toStartWith('vapid t=')
        ->and($history[0]['request']->getHeaderLine('TTL'))->toBe('86400')
        ->and((string) $history[0]['request']->getBody())->not->toContain('Newsstand test alert')
        ->and($this->user->pushSubscriptions()->pluck('endpoint')->all())->toBe(['https://8.8.8.8/push/real']);
})->skip(fn () => ! PushService::supported(), 'PHP’s OpenSSL cannot create EC keys here (set OPENSSL_CONF on Windows).');
