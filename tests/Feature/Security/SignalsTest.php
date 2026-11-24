<?php

use App\Jobs\EnrichCve;
use App\Jobs\EvaluateRules;
use App\Jobs\ProcessEntrySignals;
use App\Models\Cve;
use App\Models\Entry;
use App\Models\Feed;
use App\Models\Rule;
use App\Models\User;
use App\Services\Clustering\StoryClusterer;
use App\Services\Reader\EntryQuery;
use App\Services\Reader\EntryStates;
use App\Services\Reader\TopStories;
use App\Services\Rules\RuleEvaluator;
use App\Services\Security\CveExtractor;
use App\Services\Security\KevSync;
use App\Services\Security\NvdClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

function securityFixture(string $name): string
{
    return file_get_contents(base_path("tests/Fixtures/security/{$name}"));
}

function newsEntry(Feed $feed, string $title, array $attrs = []): Entry
{
    return $feed->entries()->create([
        'guid' => $title.uniqid(), 'guid_hash' => sha1($title.uniqid('', true)), 'title' => $title,
        'published_at' => now()->subHour(), ...$attrs,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->feedA = Feed::factory()->create(['title' => 'BleepingComputer']);
    $this->feedB = Feed::factory()->create(['title' => 'The Hacker News']);
    $this->feedC = Feed::factory()->create(['title' => 'SecurityWeek']);
    foreach ([$this->feedA, $this->feedB, $this->feedC] as $feed) {
        $this->user->subscriptions()->create(['feed_id' => $feed->id]);
    }
});

// --- CVE extraction ---

it('finds CVE identifiers, including long ones', function () {
    expect(CveExtractor::find('Patch cve-2024-3400 and CVE-2026-129448 now (CVE-2024-3400 again). Not CVE-24-1.'))
        ->toBe(['CVE-2024-3400', 'CVE-2026-129448']);
});

it('links CVEs to stories and queues an NVD lookup for new ones', function () {
    Queue::fake();
    $entry = newsEntry($this->feedA, 'PAN-OS flaw CVE-2024-3400 exploited', ['content' => '<p>Also see CVE-2023-4966.</p>']);

    app(CveExtractor::class)->extract($entry);

    expect($entry->cves()->pluck('cves.cve_id')->sort()->values()->all())->toBe(['CVE-2023-4966', 'CVE-2024-3400']);
    Queue::assertPushed(EnrichCve::class, 2);
});

// --- NVD ---

it('enriches a CVE with its CVSS score from NVD', function () {
    Http::fake(['services.nvd.nist.gov/*' => Http::response(securityFixture('nvd-CVE-2024-3400.json'))]);
    $cve = Cve::create(['cve_id' => 'CVE-2024-3400']);

    expect(app(NvdClient::class)->enrich($cve))->toBeTrue();

    $cve->refresh();
    expect($cve->cvss_score)->toBe(10.0)
        ->and($cve->severity)->toBe('critical')
        ->and($cve->description)->toContain('command injection')
        ->and($cve->nvd_checked_at)->not->toBeNull();
});

it('handles CVEs NVD does not know yet', function () {
    Http::fake(['*' => Http::response(['vulnerabilities' => []])]);
    $cve = Cve::create(['cve_id' => 'CVE-2026-999999']);

    expect(app(NvdClient::class)->enrich($cve))->toBeFalse()
        ->and($cve->fresh()->cvss_score)->toBeNull()
        ->and($cve->fresh()->nvd_checked_at)->not->toBeNull();
});

it('backs off quietly when NVD rate limits us', function () {
    Http::fake(['*' => Http::response('Too Many Requests', 429)]);
    $cve = Cve::create(['cve_id' => 'CVE-2024-3400']);

    $job = (new EnrichCve($cve))->withFakeQueueInteractions();
    $job->handle(app(NvdClient::class));

    $job->assertReleased(60);
    expect($cve->fresh()->cvss_score)->toBeNull();
});

it('re-runs rules when a CVE gets its first score', function () {
    Queue::fake([EvaluateRules::class]);
    Http::fake(['*' => Http::response(securityFixture('nvd-CVE-2024-3400.json'))]);
    $entry = newsEntry($this->feedA, 'CVE-2024-3400');
    $cve = Cve::create(['cve_id' => 'CVE-2024-3400']);
    $entry->cves()->attach($cve);

    (new EnrichCve($cve))->handle(app(NvdClient::class));

    Queue::assertPushed(EvaluateRules::class, fn ($job) => $job->entryIds === [$entry->id]);
});

// --- KEV ---

it('imports the CISA KEV catalog', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(securityFixture('kev.json'))]);
    Cve::create(['cve_id' => 'CVE-2024-3400', 'description' => 'From NVD']);

    expect(app(KevSync::class)->sync())->toBe(['total' => 3, 'new' => 3]);

    $paloAlto = Cve::where('cve_id', 'CVE-2024-3400')->first();
    expect($paloAlto->in_kev)->toBeTrue()
        ->and($paloAlto->kev_vendor)->toBe('Palo Alto Networks')
        ->and($paloAlto->kev_ransomware)->toBeTrue()
        ->and($paloAlto->description)->toBe('From NVD')
        ->and(Cve::where('in_kev', true)->count())->toBe(3);

    // Idempotent.
    expect(app(KevSync::class)->sync())->toBe(['total' => 3, 'new' => 0]);
});

it('re-runs rules for stories about newly exploited CVEs', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(securityFixture('kev.json'))]);
    $entry = newsEntry($this->feedA, 'Citrix Bleed CVE-2023-4966');
    $entry->cves()->attach(Cve::create(['cve_id' => 'CVE-2023-4966']));

    app(KevSync::class)->sync();

    Queue::assertPushed(EvaluateRules::class, fn ($job) => in_array($entry->id, $job->entryIds, true));
});

// --- Clustering ---

it('groups the same story from different feeds', function () {
    $a = newsEntry($this->feedA, 'Palo Alto Networks warns of PAN-OS zero-day exploited in attacks');
    $b = newsEntry($this->feedB, 'PAN-OS zero-day exploited in attacks, Palo Alto Networks warns customers');
    $c = newsEntry($this->feedC, 'Microsoft fixes Outlook calendar bug');

    $clusterer = app(StoryClusterer::class);
    $clusterer->cluster($a);
    $cluster = $clusterer->cluster($b);
    $clusterer->cluster($c);

    expect($cluster)->not->toBeNull()
        ->and($cluster->size)->toBe(2)
        ->and($a->fresh()->cluster_id)->toBe($cluster->id)
        ->and($c->fresh()->cluster_id)->toBeNull();
});

it('groups stories about the same specific CVE', function () {
    $a = newsEntry($this->feedA, 'Firewall maker patches critical bug');
    $b = newsEntry($this->feedB, 'Hackers hit network appliances');
    $cve = Cve::create(['cve_id' => 'CVE-2024-3400']);
    $a->cves()->attach($cve);
    $b->cves()->attach($cve);

    expect(app(StoryClusterer::class)->cluster($b)?->size)->toBe(2);
});

it('does not group stories from the same feed or with unrelated titles', function () {
    $a = newsEntry($this->feedA, 'Ransomware gang leaks hospital data');
    $b = newsEntry($this->feedA, 'Ransomware gang leaks hospital data again');
    $c = newsEntry($this->feedB, 'New Linux kernel released');

    expect(app(StoryClusterer::class)->cluster($b))->toBeNull()
        ->and(app(StoryClusterer::class)->cluster($c))->toBeNull();
});

it('processes new entries from the fetch pipeline', function () {
    Queue::fake([EnrichCve::class, EvaluateRules::class]);
    $a = newsEntry($this->feedA, 'Ivanti Connect Secure zero-day CVE-2025-0282 exploited');
    $b = newsEntry($this->feedB, 'Ivanti warns Connect Secure zero-day CVE-2025-0282 exploited');

    (new ProcessEntrySignals([$a->id, $b->id]))->handle(app(CveExtractor::class), app(StoryClusterer::class));

    expect($a->fresh()->signals_processed)->toBeTrue()
        ->and($a->fresh()->cluster_id)->not->toBeNull()
        ->and($a->fresh()->cluster_id)->toBe($b->fresh()->cluster_id);
    Queue::assertPushed(EvaluateRules::class);
});

// --- Rules ---

it('applies a keyword rule once, even after the user undoes it', function () {
    $tag = $this->user->tags()->create(['name' => 'ransomware']);
    $rule = $this->user->rules()->create([
        'name' => 'Ransomware', 'match' => 'all',
        'conditions' => [['type' => 'keyword', 'value' => 'lockbit, ransomware']],
        'actions' => ['highlight' => 'red', 'tag_id' => $tag->id, 'star' => true],
    ]);
    $hit = newsEntry($this->feedA, 'LockBit claims attack on city');
    $miss = newsEntry($this->feedA, 'Chrome update released');

    expect(app(RuleEvaluator::class)->evaluateEntries([$hit->id, $miss->id]))->toBe(1);

    $state = $this->user->entryStates()->find($hit->id)->pivot;
    expect($state->highlight)->toBe('red')
        ->and($state->starred_at)->not->toBeNull()
        ->and($tag->entries()->pluck('entries.id')->all())->toBe([$hit->id])
        ->and($rule->fresh()->matches_count)->toBe(1);

    // Undo the star; re-evaluation must not star it again.
    app(EntryStates::class)->setColumns($this->user, $hit->id, ['starred_at' => null]);
    app(RuleEvaluator::class)->evaluateEntries([$hit->id]);
    expect($this->user->entryStates()->find($hit->id)->pivot->starred_at)->toBeNull();
});

it('does not apply a rule twice when another job claims the story first', function () {
    $rule = $this->user->rules()->create([
        'name' => 'Ransomware', 'match' => 'all',
        'conditions' => [['type' => 'keyword', 'value' => 'lockbit']],
        'actions' => ['star' => true],
    ]);
    $hit = newsEntry($this->feedA, 'LockBit claims attack on city');

    // A parallel job records the match right after this one looked at rule_entry.
    $claimed = false;
    DB::listen(function ($query) use (&$claimed, $rule, $hit) {
        if (! $claimed && str_contains($query->sql, 'from "rule_entry"')) {
            $claimed = true;
            DB::table('rule_entry')->insert(['rule_id' => $rule->id, 'entry_id' => $hit->id, 'created_at' => now()]);
        }
    });

    expect(app(RuleEvaluator::class)->evaluateEntries([$hit->id]))->toBe(0)
        ->and($claimed)->toBeTrue()
        ->and($rule->fresh()->matches_count)->toBe(0)
        ->and($this->user->entryStates()->find($hit->id))->toBeNull();
});

it('matches security conditions and any/all', function () {
    $entry = newsEntry($this->feedA, 'Exploited firewall bug');
    $entry->cves()->attach(Cve::create(['cve_id' => 'CVE-2024-3400', 'cvss_score' => 10.0, 'severity' => 'critical', 'in_kev' => true]));
    $entry->load('cves', 'cluster');

    $make = fn (array $conditions, string $match = 'all') => new Rule(['match' => $match, 'conditions' => $conditions, 'actions' => ['star' => true]]);
    $evaluator = app(RuleEvaluator::class);

    expect($evaluator->matches($make([['type' => 'kev', 'value' => null]]), $entry, null))->toBeTrue()
        ->and($evaluator->matches($make([['type' => 'cvss', 'value' => 9]]), $entry, null))->toBeTrue()
        ->and($evaluator->matches($make([['type' => 'cvss', 'value' => 10.5]]), $entry, null))->toBeFalse()
        ->and($evaluator->matches($make([['type' => 'has_cve', 'value' => null], ['type' => 'feed', 'value' => $this->feedB->id]]), $entry, null))->toBeFalse()
        ->and($evaluator->matches($make([['type' => 'has_cve', 'value' => null], ['type' => 'feed', 'value' => $this->feedB->id]], 'any'), $entry, null))->toBeTrue()
        ->and($evaluator->matches($make([['type' => 'regex', 'value' => 'fire(wall|fox)']]), $entry, null))->toBeTrue()
        ->and($evaluator->matches($make([['type' => 'regex', 'value' => '(unclosed']]), $entry, null))->toBeFalse()
        ->and($evaluator->matches($make([['type' => 'sources', 'value' => 2]]), $entry, null))->toBeFalse();
});

it('mutes stories so they leave feed listings', function () {
    $this->user->rules()->create([
        'name' => 'No crypto', 'match' => 'all',
        'conditions' => [['type' => 'title', 'value' => 'bitcoin']], 'actions' => ['mute' => true],
    ]);
    $muted = newsEntry($this->feedA, 'Bitcoin price swings');
    newsEntry($this->feedA, 'Kernel patch');

    app(RuleEvaluator::class)->evaluateEntries([$muted->id]);

    $reader = Livewire::test('pages::reader.index')->set('show', 'all');

    expect(listedTitles($reader))->toContain('Kernel patch')->not->toContain('Bitcoin price swings');
});

it('only applies rules for subscribers of the feed', function () {
    $other = User::factory()->create();
    $other->rules()->create(['name' => 'All', 'match' => 'all', 'conditions' => [['type' => 'keyword', 'value' => 'patch']], 'actions' => ['star' => true]]);
    $entry = newsEntry($this->feedA, 'Patch now');

    expect(app(RuleEvaluator::class)->evaluateEntries([$entry->id]))->toBe(0);
});

// --- Top stories & reader signals ---

it('ranks top stories by coverage and severity, one per cluster', function () {
    $a = newsEntry($this->feedA, 'Palo Alto Networks warns of PAN-OS zero-day exploited in attacks');
    $b = newsEntry($this->feedB, 'PAN-OS zero-day exploited in attacks, Palo Alto Networks warns');
    $c = newsEntry($this->feedC, 'Palo Alto Networks PAN-OS zero-day exploited in attacks worldwide');
    $quiet = newsEntry($this->feedA, 'Small library update');
    $kev = newsEntry($this->feedB, 'Exploited Citrix bug', ['published_at' => now()->subHours(3)]);
    $kev->cves()->attach(Cve::create(['cve_id' => 'CVE-2023-4966', 'in_kev' => true, 'cvss_score' => 9.4]));

    foreach ([$a, $b, $c] as $entry) {
        app(StoryClusterer::class)->cluster($entry->fresh());
    }

    $top = app(TopStories::class)->for($this->user);

    // An actively exploited critical CVE outranks a widely covered story;
    // both outrank a quiet one. The 3-source story shows once, as its first report.
    expect($top->pluck('title')->all())->toBe([
        'Exploited Citrix bug',
        'Palo Alto Networks warns of PAN-OS zero-day exploited in attacks',
        'Small library update',
    ])->and($top->get(1)->top_sources)->toBe(3);

    Livewire::withQueryParams(['s' => 'top'])->test('pages::reader.index')
        ->assertSee('Top stories')
        ->assertSee('3 sources')
        ->assertSee('Exploited');
});

it('shows CVE details under an opened story', function () {
    $entry = newsEntry($this->feedA, 'Citrix Bleed is back');
    $entry->cves()->attach(Cve::create([
        'cve_id' => 'CVE-2023-4966', 'cvss_score' => 9.4, 'severity' => 'critical',
        'in_kev' => true, 'kev_ransomware' => true, 'kev_due_on' => '2023-11-08',
    ]));

    Livewire::test('pages::reader.index')
        ->assertSee('CVSS 9.4')
        ->call('select', $entry->id)
        ->assertSee('CVE-2023-4966')
        ->assertSee('Critical 9.4')
        ->assertSee('Used in ransomware')
        ->assertSee('CISA fix deadline');

    expect(app(EntryQuery::class)->signalsFor([$entry->id])[$entry->id]['cves'])->toHaveCount(1);
});

// --- Pages ---

it('lists vulnerabilities mentioned in your feeds', function () {
    $exploited = newsEntry($this->feedA, 'Story one');
    $exploited->cves()->attach(Cve::create(['cve_id' => 'CVE-2024-3400', 'cvss_score' => 10, 'severity' => 'critical', 'in_kev' => true, 'kev_vendor' => 'Palo Alto Networks', 'kev_product' => 'PAN-OS']));
    newsEntry($this->feedB, 'Story two')->cves()->attach(Cve::create(['cve_id' => 'CVE-2025-1111', 'cvss_score' => 5.0, 'severity' => 'medium']));
    $unsubscribed = newsEntry(Feed::factory()->create(), 'Elsewhere');
    $unsubscribed->cves()->attach(Cve::create(['cve_id' => 'CVE-2025-2222']));
    Cve::create(['cve_id' => 'CVE-2026-0001', 'in_kev' => true, 'kev_added_on' => now()->subDays(2)->toDateString(), 'kev_name' => 'Fresh KEV entry']);

    $this->get(route('security'))->assertOk()->assertSee('Vulnerabilities');

    Livewire::test('pages::security.index')
        ->assertSee('CVE-2024-3400')
        ->assertSee('CVE-2025-1111')
        ->assertDontSee('CVE-2025-2222')
        ->set('filter', 'kev')
        ->assertSee('CVE-2024-3400')
        ->assertDontSee('CVE-2025-1111')
        ->set('filter', 'kev_new')
        ->assertSee('Fresh KEV entry')
        ->set('filter', 'mentioned')
        ->set('search', 'pan-os')
        ->assertSee('CVE-2024-3400')
        ->assertDontSee('CVE-2025-1111');
});

it('creates, edits, toggles and deletes rules', function () {
    $tag = $this->user->tags()->create(['name' => 'vendor']);
    newsEntry($this->feedA, 'Fortinet patches FortiOS');

    Livewire::test('pages::rules.index')
        ->call('create')
        ->set('name', 'Fortinet')
        ->set('conditions.0.type', 'keyword')
        ->set('conditions.0.value', 'fortinet, fortios')
        ->set('highlight', 'violet')
        ->set('tagId', (string) $tag->id)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('Fortinet');

    $rule = $this->user->rules()->sole();
    expect($rule->actions)->toBe(['highlight' => 'violet', 'tag_id' => $tag->id])
        ->and($rule->matches_count)->toBe(1);

    Livewire::test('pages::rules.index')
        ->call('edit', $rule->id)
        ->set('match', 'any')
        ->call('addCondition')
        ->set('conditions.1.type', 'cvss')
        ->assertSet('conditions.1.value', 9)
        ->call('save')
        ->assertHasNoErrors()
        ->call('toggle', $rule->id);

    expect($rule->fresh()->match)->toBe('any')
        ->and($rule->fresh()->conditions)->toHaveCount(2)
        ->and($rule->fresh()->enabled)->toBeFalse();

    Livewire::test('pages::rules.index')->call('delete', $rule->id);
    expect($this->user->rules()->count())->toBe(0);
});

it('validates rules', function () {
    $foreignFeed = Feed::factory()->create();

    Livewire::test('pages::rules.index')
        ->call('create')
        ->set('name', 'Bad')
        ->set('conditions.0.type', 'regex')
        ->set('conditions.0.value', '(unclosed')
        ->set('star', true)
        ->call('save')
        ->assertHasErrors('conditions.0.value')
        ->set('conditions.0.type', 'feed')
        ->set('conditions.0.value', (string) $foreignFeed->id)
        ->call('save')
        ->assertHasErrors('conditions.0.value')
        ->set('conditions.0.type', 'keyword')
        ->set('conditions.0.value', 'ok')
        ->set('star', false)
        ->call('save')
        ->assertHasErrors('actions');

    expect($this->user->rules()->count())->toBe(0);
});

it('adds security presets', function () {
    Livewire::test('pages::rules.index')
        ->assertSee('Start with a security preset')
        ->call('useTemplate', 'exploited')
        ->assertSee('Actively exploited vulnerabilities');

    expect($this->user->rules()->sole()->conditions)->toBe([['type' => 'kev', 'value' => null]]);
});
