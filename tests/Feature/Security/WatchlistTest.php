<?php

use App\Jobs\EnrichCve;
use App\Jobs\EvaluateRules;
use App\Models\Cve;
use App\Models\CveProduct;
use App\Models\Feed;
use App\Models\User;
use App\Services\Rules\RuleEvaluator;
use App\Services\Security\KevSync;
use App\Services\Security\NvdClient;
use App\Services\Watchlist\WatchlistMatcher;
use App\Support\ProductName;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function watchStory(Feed $feed, string $title, array $attrs = [])
{
    return $feed->entries()->create([
        'guid' => $title, 'guid_hash' => sha1($feed->id.$title), 'title' => $title,
        'published_at' => now()->subHour(), ...$attrs,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->feed = Feed::factory()->create(['title' => 'BleepingComputer']);
    $this->user->subscriptions()->create(['feed_id' => $this->feed->id]);
});

// --- Product data ---

it('normalizes names and parses CPE strings', function () {
    expect(ProductName::key('Palo Alto Networks'))->toBe(ProductName::key('paloaltonetworks'))
        ->and(ProductName::key('PAN-OS'))->toBe('panos')
        ->and(ProductName::fromCpe('cpe:2.3:o:paloaltonetworks:pan-os:10.2.0:-:*:*:*:*:*:*'))->toBe(['paloaltonetworks', 'pan-os'])
        ->and(ProductName::fromCpe('cpe:2.3:a:microsoft:exchange_server:2019:*:*:*:*:*:*:*'))->toBe(['microsoft', 'exchange server'])
        ->and(ProductName::fromCpe('not a cpe'))->toBeNull();
});

it('stores affected products from NVD and CISA KEV', function () {
    Queue::fake();
    Http::fake([
        'services.nvd.nist.gov/*' => Http::response(file_get_contents(base_path('tests/Fixtures/security/nvd-CVE-2024-3400.json'))),
        '*' => Http::response(file_get_contents(base_path('tests/Fixtures/security/kev.json'))),
    ]);

    $cve = Cve::create(['cve_id' => 'CVE-2024-3400']);
    app(NvdClient::class)->enrich($cve);
    app(KevSync::class)->sync();

    // NVD's "paloaltonetworks / pan-os" and KEV's "Palo Alto Networks / PAN-OS" are the
    // same product after normalizing, so it's stored once.
    $products = $cve->products()->get();
    expect($products)->toHaveCount(1)
        ->and($products->first()->only('vendor_key', 'product_key', 'source'))
        ->toBe(['vendor_key' => 'paloaltonetworks', 'product_key' => 'panos', 'source' => 'nvd']);

    // KEV-only entries get KEV's names.
    $citrix = CveProduct::where('vendor_key', 'citrix')->first();
    expect($citrix->vendor)->toBe('Citrix')->and($citrix->source)->toBe('kev');
});

// --- Matching ---

it('matches stories through the CVEs they mention', function () {
    $cve = Cve::create(['cve_id' => 'CVE-2024-3400', 'in_kev' => true]);
    CveProduct::record($cve->id, [['paloaltonetworks', 'pan-os']], 'nvd');
    $story = watchStory($this->feed, 'Firewall zero-day exploited');
    $story->cves()->attach($cve);
    $other = watchStory($this->feed, 'Unrelated news');

    $item = $this->user->watchlist()->create(['vendor' => 'Palo Alto Networks', 'product' => 'PAN-OS']);

    expect(app(WatchlistMatcher::class)->matchItem($item))->toBe(1)
        ->and($item->entries()->pluck('entries.id')->all())->toBe([$story->id])
        ->and($item->entries()->first()->pivot->reason)->toBe('cve');
});

it('matches stories that name the product, as a whole word', function () {
    $hit = watchStory($this->feed, 'Fortinet warns of FortiOS SSL-VPN attacks');
    $alias = watchStory($this->feed, 'FortiGate devices targeted');
    watchStory($this->feed, 'FortiOSX is not a real product');

    $item = $this->user->watchlist()->create(['vendor' => 'Fortinet', 'product' => 'FortiOS', 'aliases' => ['FortiGate']]);
    app(WatchlistMatcher::class)->matchItem($item);

    expect($item->entries()->pluck('entries.id')->sort()->values()->all())->toBe([$hit->id, $alias->id])
        ->and($item->entries()->first()->pivot->reason)->toBe('text');
});

it('matches a vendor-only item against any of its products', function () {
    $cve = Cve::create(['cve_id' => 'CVE-2023-4966']);
    CveProduct::record($cve->id, [['Citrix', 'NetScaler ADC and NetScaler Gateway']], 'kev');
    $story = watchStory($this->feed, 'Session hijacking bug patched');
    $story->cves()->attach($cve);

    $item = $this->user->watchlist()->create(['vendor' => 'Citrix']);
    app(WatchlistMatcher::class)->matchItem($item);

    expect($item->entries()->count())->toBe(1)
        ->and(app(WatchlistMatcher::class)->cveIdsFor($this->user)->all())->toBe([$cve->id]);
});

it('matches new stories for every subscriber, and only for them', function () {
    $other = User::factory()->create();
    $other->watchlist()->create(['vendor' => 'Ivanti']);
    $this->user->watchlist()->create(['vendor' => 'Ivanti']);
    $story = watchStory($this->feed, 'Ivanti Connect Secure flaw');

    expect(app(WatchlistMatcher::class)->matchEntries([$story->id]))->toBe(1); // $other isn't subscribed

    expect(app(WatchlistMatcher::class)->labelsFor($this->user, [$story->id])[$story->id]->all())->toBe(['Ivanti'])
        ->and(app(WatchlistMatcher::class)->labelsFor($other, [$story->id]))->toBeEmpty();
});

it('runs watchlist matching from the signals pipeline', function () {
    Queue::fake([EnrichCve::class]);
    $this->user->watchlist()->create(['vendor' => 'VMware', 'product' => 'vCenter']);
    $story = watchStory($this->feed, 'VMware patches vCenter zero-day');

    (new EvaluateRules([$story->id]))->handle(app(RuleEvaluator::class), app(WatchlistMatcher::class));

    expect($this->user->watchlist()->first()->entries()->count())->toBe(1);
});

it('re-runs matching when NVD reports new affected products', function () {
    Queue::fake([EvaluateRules::class]);
    Http::fake(['*' => Http::response(file_get_contents(base_path('tests/Fixtures/security/nvd-CVE-2024-3400.json')))]);
    $cve = Cve::create(['cve_id' => 'CVE-2024-3400', 'cvss_score' => 10.0]); // already scored
    watchStory($this->feed, 'Story')->cves()->attach($cve);

    (new EnrichCve($cve))->handle(app(NvdClient::class));

    Queue::assertPushed(EvaluateRules::class);
});

// --- Rules ---

it('supports a "concerns my watchlist" rule condition', function () {
    $this->user->watchlist()->create(['vendor' => 'Fortinet']);
    $this->user->rules()->create([
        'name' => 'My stack', 'match' => 'all',
        'conditions' => [['type' => 'watchlist', 'value' => null]], 'actions' => ['highlight' => 'violet', 'star' => true],
    ]);
    $story = watchStory($this->feed, 'Fortinet patches critical bug');
    $other = watchStory($this->feed, 'Something else entirely');

    (new EvaluateRules([$story->id, $other->id]))->handle(app(RuleEvaluator::class), app(WatchlistMatcher::class));

    expect($this->user->entryStates()->find($story->id)?->pivot->highlight)->toBe('violet')
        ->and($this->user->entryStates()->find($other->id))->toBeNull();
});

// --- Pages ---

it('manages the watchlist and backfills matches', function () {
    watchStory($this->feed, 'Citrix Bleed is back');
    CveProduct::record(Cve::create(['cve_id' => 'CVE-2023-4966', 'in_kev' => true, 'kev_vendor' => 'Citrix'])->id, [['Citrix', 'NetScaler ADC']], 'kev');

    Livewire::test('pages::watchlist.index')
        ->assertSee('Nothing watched yet')
        ->set('vendor', 'Citrix')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('1 story in 30 days')
        ->assertSee('1 known CVE')
        ->assertSee('1 actively exploited')
        ->set('vendor', 'citrix')
        ->call('save')
        ->assertHasErrors('vendor');

    $item = $this->user->watchlist()->sole();

    Livewire::test('pages::watchlist.index')
        ->call('edit', $item->id)
        ->set('product', 'NetScaler')
        ->set('aliases', 'Citrix Bleed, ADC')
        ->call('save')
        ->assertHasNoErrors();
    expect($item->fresh()->only('product', 'aliases'))->toBe(['product' => 'NetScaler', 'aliases' => ['Citrix Bleed', 'ADC']]);

    Livewire::test('pages::watchlist.index')->call('remove', $item->id);
    expect($this->user->watchlist()->count())->toBe(0);
});

it('keeps watchlists private', function () {
    $theirs = User::factory()->create()->watchlist()->create(['vendor' => 'Secret Corp']);

    Livewire::test('pages::watchlist.index')->assertDontSee('Secret Corp')->call('remove', $theirs->id)->assertNotFound();
    expect($theirs->fresh())->not->toBeNull();
});

it('shows watched stories in the reader with a badge', function () {
    $this->user->watchlist()->create(['vendor' => 'Ivanti']);
    $hit = watchStory($this->feed, 'Ivanti EPMM exploited');
    watchStory($this->feed, 'Linux release');
    app(WatchlistMatcher::class)->matchEntries([$hit->id]);

    Livewire::withQueryParams(['s' => 'watchlist'])->test('pages::reader.index')
        ->assertSee('Your watchlist')
        ->assertSee('Ivanti EPMM exploited')
        ->assertDontSee('Linux release')
        ->assertSeeHtml('data-test="watch-badge"');
});

it('shows a CVE page with products and the stories you can see', function () {
    $cve = Cve::create([
        'cve_id' => 'CVE-2024-3400', 'cvss_score' => 10.0, 'severity' => 'critical', 'in_kev' => true,
        'kev_name' => 'PAN-OS Command Injection', 'kev_due_on' => '2024-04-19', 'description' => 'A command injection.',
    ]);
    CveProduct::record($cve->id, [['paloaltonetworks', 'pan-os']], 'nvd');
    watchStory($this->feed, 'PAN-OS zero-day exploited')->cves()->attach($cve);
    watchStory(Feed::factory()->create(), 'Story in a feed you do not follow')->cves()->attach($cve);
    $this->user->watchlist()->create(['vendor' => 'Palo Alto Networks']);

    $this->get(route('security.cve', 'cve-2024-3400'))
        ->assertOk()
        ->assertSee('PAN-OS Command Injection')
        ->assertSee('Critical 10.0')
        ->assertSee('Fix deadline')
        ->assertSee('pan-os')
        ->assertSee('PAN-OS zero-day exploited')
        ->assertDontSee('Story in a feed you do not follow')
        ->assertSee('On your watchlist');

    $this->get('/security/cves/CVE-2099-0001')->assertNotFound();
});

it('filters vulnerabilities to my products, including ones no story mentions yet', function () {
    $this->user->watchlist()->create(['vendor' => 'Fortinet']);
    $mine = Cve::create(['cve_id' => 'CVE-2026-1111', 'in_kev' => true, 'kev_vendor' => 'Fortinet']);
    CveProduct::record($mine->id, [['Fortinet', 'FortiOS']], 'kev');
    $notMine = Cve::create(['cve_id' => 'CVE-2026-2222']);
    CveProduct::record($notMine->id, [['Microsoft', 'Windows']], 'kev');
    watchStory($this->feed, 'Windows bug')->cves()->attach($notMine);

    Livewire::test('pages::security.index')
        ->set('filter', 'mine')
        ->assertSee('CVE-2026-1111')
        ->assertDontSee('CVE-2026-2222');
});

it('offers a watchlist dashboard widget', function () {
    $this->user->watchlist()->create(['vendor' => 'Ivanti']);
    $hit = watchStory($this->feed, 'Ivanti EPMM exploited');
    app(WatchlistMatcher::class)->matchEntries([$hit->id]);
    $dashboard = $this->user->dashboards()->create(['name' => 'Mine']);
    $dashboard->widgets()->create(['type' => 'watchlist', 'config' => [], 'position' => 0]);

    Livewire::test('pages::dashboards.show', ['dashboard' => $dashboard])->assertSee('Ivanti EPMM exploited');
});
