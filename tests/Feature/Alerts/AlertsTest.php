<?php

use App\Exceptions\UnsafeUrlException;
use App\Jobs\FlushAlerts;
use App\Mail\AlertMail;
use App\Models\Alert;
use App\Models\AlertChannel;
use App\Models\Cve;
use App\Models\CveProduct;
use App\Models\Feed;
use App\Models\Setting;
use App\Models\User;
use App\Services\Alerts\AlertDispatcher;
use App\Services\Alerts\AlertMessage;
use App\Services\Rules\RuleEvaluator;
use App\Services\Security\KevSync;
use App\Services\Watchlist\WatchlistMatcher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function channel(User $user, string $type, array $config, array $events = ['watchlist', 'kev']): AlertChannel
{
    return $user->alertChannels()->create(['type' => $type, 'name' => ucfirst($type), 'config' => $config, 'events' => $events]);
}

function alertStory(Feed $feed, string $title, array $attrs = [])
{
    return $feed->entries()->create([
        'guid' => $title, 'guid_hash' => sha1($feed->id.$title), 'title' => $title, 'url' => 'https://news.example.com/'.md5($title),
        'summary' => "Summary of {$title}", 'published_at' => now()->subHour(), ...$attrs,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->feed = Feed::factory()->create(['title' => 'BleepingComputer']);
    $this->user->subscriptions()->create(['feed_id' => $this->feed->id]);
});

// --- Senders ---

it('sends to ntfy as JSON with priority and click', function () {
    Http::fake(['*' => Http::response(['id' => 'x'])]);
    $ch = channel($this->user, 'ntfy', ['server' => 'https://ntfy.example.com', 'topic' => 'news-abc', 'token' => 'tk_secret']);

    app(AlertDispatcher::class)->sendNow($ch, new AlertMessage('Fortinet zero-day ✓', 'Body', 'https://x.test/a', 'critical', 'Watchlist: Fortinet'));

    Http::assertSent(fn (Request $r) => $r->url() === 'https://ntfy.example.com'
        && $r['topic'] === 'news-abc' && $r['title'] === 'Fortinet zero-day ✓' && $r['priority'] === 5
        && $r['click'] === 'https://x.test/a' && $r->hasHeader('Authorization', 'Bearer tk_secret'));
});

it('sends to Gotify, Slack, Discord and Teams in their formats', function () {
    Http::fake(['*' => Http::response('ok')]);
    $msg = new AlertMessage('Title <b>', 'Body text', 'https://x.test/a', 'high', 'Rule: test');

    app(AlertDispatcher::class)->sendNow(channel($this->user, 'gotify', ['url' => 'https://gotify.example.com/', 'token' => 'gt']), $msg);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://gotify.example.com/message' && $r->hasHeader('X-Gotify-Key', 'gt') && $r['priority'] === 5);

    app(AlertDispatcher::class)->sendNow(channel($this->user, 'slack', ['url' => 'https://hooks.slack.com/services/T/B/x']), $msg);
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://hooks.slack.com/')
        && str_contains($r['blocks'][0]['text']['text'], 'Title &lt;b&gt;') && str_contains($r['blocks'][0]['text']['text'], '<https://x.test/a|'));

    app(AlertDispatcher::class)->sendNow(channel($this->user, 'discord', ['url' => 'https://discord.com/api/webhooks/1/abc']), $msg);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'discord.com') && $r['allowed_mentions'] === ['parse' => []] && $r['embeds'][0]['url'] === 'https://x.test/a');

    app(AlertDispatcher::class)->sendNow(channel($this->user, 'teams', ['url' => 'https://prod.example.logic.azure.com/workflows/abc']), $msg);
    Http::assertSent(fn (Request $r) => ($r['attachments'][0]['contentType'] ?? null) === 'application/vnd.microsoft.card.adaptive'
        && $r['attachments'][0]['content']['actions'][0]['url'] === 'https://x.test/a');
});

it('sends email alerts', function () {
    Mail::fake();

    app(AlertDispatcher::class)->sendNow(channel($this->user, 'email', ['address' => 'soc@example.com']), new AlertMessage('Exploited bug', 'Body', null, 'critical'));

    Mail::assertSent(AlertMail::class, fn ($mail) => $mail->hasTo('soc@example.com') && $mail->envelope()->subject === '[Critical] Exploited bug');
});

it('refuses private network destinations unless an admin allows them', function () {
    config(['newsstand.fetch.block_private_hosts' => true]);
    Http::fake();
    $ch = channel($this->user, 'gotify', ['url' => 'http://192.168.1.20', 'token' => 'x']);

    expect(fn () => app(AlertDispatcher::class)->sendNow($ch, AlertMessage::test()))->toThrow(UnsafeUrlException::class);
    Http::assertNothingSent();

    Setting::set('alerts.allow_private', true);
    Http::fake(['*' => Http::response('ok')]);
    app(AlertDispatcher::class)->sendNow($ch, AlertMessage::test());
    Http::assertSentCount(1);
});

// --- Delivery ---

it('sends each story once per channel', function () {
    Queue::fake();
    $ch = channel($this->user, 'ntfy', ['server' => 'https://ntfy.example.com', 'topic' => 't']);
    $dispatcher = app(AlertDispatcher::class);

    expect($dispatcher->queue($ch, AlertMessage::test(), 'entry:1'))->toBeTrue()
        ->and($dispatcher->queue($ch, AlertMessage::test(), 'entry:1'))->toBeFalse()
        ->and(Alert::count())->toBe(1);
    Queue::assertPushed(FlushAlerts::class, 1);
});

it('sends a few alerts one by one and a burst as one summary', function () {
    Queue::fake();
    Http::fake(['*' => Http::response('ok')]);
    $ch = channel($this->user, 'ntfy', ['server' => 'https://ntfy.example.com', 'topic' => 't']);
    $dispatcher = app(AlertDispatcher::class);

    foreach (range(1, 2) as $i) {
        $dispatcher->queue($ch, new AlertMessage("Story {$i}", 'b'), "entry:{$i}");
    }
    (new FlushAlerts($ch->id))->handle();
    Http::assertSentCount(2);

    foreach (range(3, 8) as $i) {
        $dispatcher->queue($ch, new AlertMessage("Story {$i}", 'b', null, $i === 5 ? 'critical' : 'info'), "entry:{$i}");
    }
    (new FlushAlerts($ch->id))->handle();
    Http::assertSentCount(3);
    Http::assertSent(fn (Request $r) => ($r['title'] ?? '') === '6 new Newsstand alerts' && $r['priority'] === 5);

    expect(Alert::where('status', 'sent')->count())->toBe(8)
        ->and($ch->fresh()->last_sent_at)->not->toBeNull();
});

it('records delivery errors on the channel', function () {
    Queue::fake();
    Http::fake(['*' => Http::response('invalid topic', 400)]);
    $ch = channel($this->user, 'ntfy', ['server' => 'https://ntfy.example.com', 'topic' => 't']);
    app(AlertDispatcher::class)->queue($ch, AlertMessage::test(), 'test:1');

    $job = (new FlushAlerts($ch->id))->withFakeQueueInteractions();
    expect(fn () => $job->handle())->toThrow(RuntimeException::class);

    expect($ch->fresh()->last_error)->toContain('ntfy answered HTTP 400')
        ->and(Alert::sole()->status)->toBe('pending'); // retried by the queue
});

// --- Triggers: watchlist ---

it('alerts on watchlist stories according to the item\'s level', function () {
    Queue::fake();
    channel($this->user, 'ntfy', ['server' => 'https://ntfy.example.com', 'topic' => 't']);
    $any = $this->user->watchlist()->create(['vendor' => 'Ivanti', 'alert_level' => 'any']);
    $kevOnly = $this->user->watchlist()->create(['vendor' => 'Fortinet', 'alert_level' => 'kev']);
    $off = $this->user->watchlist()->create(['vendor' => 'Cisco', 'alert_level' => 'off']);

    $ivanti = alertStory($this->feed, 'Ivanti EPMM flaw');            // any → alert
    $fortiText = alertStory($this->feed, 'Fortinet earnings');          // kev-only, no CVE → none
    $fortiKev = alertStory($this->feed, 'FortiOS bug exploited');       // kev-only + KEV CVE → alert
    $cve = Cve::create(['cve_id' => 'CVE-2026-1111', 'in_kev' => true]);
    CveProduct::record($cve->id, [['Fortinet', 'FortiOS']], 'kev');
    $fortiKev->cves()->attach($cve);
    alertStory($this->feed, 'Cisco patches');                         // off → none

    app(WatchlistMatcher::class)->matchEntries(Feed::find($this->feed->id)->entries()->pluck('id')->all());

    $keys = Alert::pluck('dedupe_key')->sort()->values()->all();
    expect($keys)->toBe(['entry:'.$ivanti->id, 'entry:'.$fortiKev->id]);
    expect(Alert::where('dedupe_key', 'entry:'.$fortiKev->id)->first()->payload['level'])->toBe('critical');
});

it('never alerts when back-filling, for old stories, or to channels without the event', function () {
    Queue::fake();
    channel($this->user, 'ntfy', ['server' => 'https://ntfy.example.com', 'topic' => 't'], events: ['kev']); // no watchlist event
    $item = $this->user->watchlist()->create(['vendor' => 'Ivanti', 'alert_level' => 'any']);
    $story = alertStory($this->feed, 'Ivanti flaw');

    app(WatchlistMatcher::class)->matchEntries([$story->id]);
    expect(Alert::count())->toBe(0);

    channel($this->user, 'slack', ['url' => 'https://hooks.slack.com/services/x'], events: ['watchlist']);
    app(WatchlistMatcher::class)->matchItem($item); // backfill
    $old = alertStory($this->feed, 'Old Ivanti story', ['published_at' => now()->subDays(20)]);
    app(WatchlistMatcher::class)->matchEntries([$old->id]);

    expect(Alert::count())->toBe(0);
});

// --- Triggers: KEV ---

it('alerts when a watched product is newly added to KEV, but not on a first import of old entries', function () {
    Queue::fake();
    channel($this->user, 'ntfy', ['server' => 'https://ntfy.example.com', 'topic' => 't']);
    $this->user->watchlist()->create(['vendor' => 'Apache', 'alert_level' => 'kev']);
    $catalog = json_decode(file_get_contents(base_path('tests/Fixtures/security/kev.json')), true);

    // The fixture's entries were added long ago (except the 2026 one); make one recent Apache entry.
    $catalog['vulnerabilities'] = [
        ['cveID' => 'CVE-2026-55555', 'vendorProject' => 'Apache', 'product' => 'Roller', 'vulnerabilityName' => 'Apache Roller RCE',
            'dateAdded' => now()->toDateString(), 'dueDate' => now()->addDays(14)->toDateString(), 'knownRansomwareCampaignUse' => 'Unknown', 'shortDescription' => 'RCE'],
        ['cveID' => 'CVE-2019-0001', 'vendorProject' => 'Apache', 'product' => 'Struts', 'vulnerabilityName' => 'Old Struts bug',
            'dateAdded' => '2021-11-03', 'dueDate' => '2022-05-03', 'knownRansomwareCampaignUse' => 'Known', 'shortDescription' => 'Old'],
    ];
    Http::fake(['*' => Http::response($catalog)]);

    app(KevSync::class)->sync();

    expect(Alert::pluck('dedupe_key')->all())->toBe(['kev:CVE-2026-55555'])
        ->and(Alert::sole()->payload['title'])->toBe('CVE-2026-55555 is being actively exploited');
});

// --- Triggers: rules ---

it('sends rule alerts for live matches only, and only to the owner\'s channels', function () {
    Queue::fake();
    $mine = channel($this->user, 'ntfy', ['server' => 'https://ntfy.example.com', 'topic' => 't'], events: []);
    $theirs = channel(User::factory()->create(), 'ntfy', ['server' => 'https://ntfy.example.com', 'topic' => 'x'], events: []);
    $rule = $this->user->rules()->create([
        'name' => 'Ransomware', 'match' => 'all', 'conditions' => [['type' => 'keyword', 'value' => 'ransomware']],
        'actions' => ['alert' => [$mine->id, $theirs->id]],
    ]);
    $backfilled = alertStory($this->feed, 'Old ransomware attack');

    app(RuleEvaluator::class)->applyToRecent($rule);
    expect(Alert::count())->toBe(0);

    $live = alertStory($this->feed, 'New ransomware gang');
    app(RuleEvaluator::class)->evaluateEntries([$live->id]);

    expect(Alert::pluck('alert_channel_id')->all())->toBe([$mine->id])
        ->and(Alert::sole()->payload['context'])->toContain('Rule: Ransomware');
});

// --- Settings page ---

it('adds, tests, edits and deletes channels, keeping secrets', function () {
    Http::fake(['*' => Http::response('ok')]);

    Livewire::test('pages::settings.alerts')
        ->call('create')
        ->set('type', 'gotify')
        ->set('fields.url', 'https://gotify.example.com')
        ->set('fields.token', 'secret-token')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('gotify.example.com')
        ->assertDontSee('secret-token');

    $ch = $this->user->alertChannels()->sole();

    Livewire::test('pages::settings.alerts')
        ->call('edit', $ch->id)
        ->assertSet('fields.token', '')        // never sent back to the browser
        ->set('name', 'Phone')
        ->call('save')
        ->assertHasNoErrors()
        ->call('test', $ch->id);

    expect($ch->fresh()->name)->toBe('Phone')
        ->and($ch->fresh()->config['token'])->toBe('secret-token')
        ->and($ch->fresh()->last_sent_at)->not->toBeNull();
    Http::assertSent(fn (Request $r) => $r->hasHeader('X-Gotify-Key', 'secret-token'));

    Livewire::test('pages::settings.alerts')->call('delete', $ch->id);
    expect($this->user->alertChannels()->count())->toBe(0);
});

it('validates channels and shows test failures', function () {
    config(['newsstand.fetch.block_private_hosts' => true]);
    Http::fake(['*' => Http::response('nope', 404)]);

    Livewire::test('pages::settings.alerts')
        ->call('create')
        ->set('type', 'slack')
        ->set('fields.url', 'https://example.com/not-slack')
        ->call('save')
        ->assertHasErrors('fields.url')
        ->set('type', 'gotify')
        ->set('fields.url', 'http://10.0.0.5')
        ->set('fields.token', 't')
        ->call('save')
        ->assertHasErrors('fields.url');

    $ch = channel($this->user, 'ntfy', ['server' => 'https://ntfy.example.com', 'topic' => 't']);
    config(['newsstand.fetch.block_private_hosts' => false]);
    Livewire::test('pages::settings.alerts')->call('test', $ch->id)->assertSee('ntfy answered HTTP 404');
});

it('keeps channels private', function () {
    $theirs = channel(User::factory()->create(), 'ntfy', ['server' => 'https://ntfy.example.com', 'topic' => 'theirs']);

    Livewire::test('pages::settings.alerts')->assertDontSee('theirs')->call('test', $theirs->id)->assertNotFound();
    Livewire::test('pages::settings.alerts')->call('delete', $theirs->id)->assertNotFound();
    expect($theirs->fresh())->not->toBeNull();
});

it('stores channel secrets encrypted', function () {
    $ch = channel($this->user, 'gotify', ['url' => 'https://g.example.com', 'token' => 'plain-secret']);

    expect(DB::table('alert_channels')->where('id', $ch->id)->value('config'))->not->toContain('plain-secret');
});
