<?php

use App\Actions\Feeds\Organizer;
use App\Actions\Feeds\SubscribeToFeed;
use App\Jobs\FetchFeed;
use App\Models\Feed;
use App\Models\User;
use App\Services\Reader\EntryQuery;
use App\Services\Reader\EntryStates;
use App\Services\Reader\ReaderSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

function story(Feed $feed, string $title, array $attrs = [])
{
    return $feed->entries()->create([
        'guid' => $title, 'guid_hash' => sha1($feed->id.$title), 'title' => $title,
        'published_at' => now(), ...$attrs,
    ]);
}

// --- Drag and drop ---

it('reorders folders', function () {
    [$a, $b, $c] = collect(['A', 'B', 'C'])->map(fn ($n, $i) => $this->user->folders()->create(['name' => $n, 'position' => $i]))->all();

    Livewire::test('pages::feeds.index')->call('sortFolder', $c->id, 0);

    expect($this->user->folders()->pluck('name')->all())->toBe(['C', 'A', 'B']);
});

it('reorders feeds within a folder and moves them between folders', function () {
    $folder = $this->user->folders()->create(['name' => 'Security']);
    $subs = collect(['Alpha', 'Bravo', 'Charlie'])->map(fn ($t) => $this->user->subscriptions()->create([
        'feed_id' => Feed::factory()->create(['title' => $t])->id,
    ]));

    // Charlie to the top of Unfiled.
    Livewire::test('pages::feeds.index')->call('sortSubscription', $subs[2]->id, 0, '0');
    $order = fn ($folderId) => Organizer::sortSubscriptions($this->user->subscriptions()->with('feed')->where('folder_id', $folderId)->get())
        ->map->displayTitle()->all();
    expect($order(null))->toBe(['Charlie', 'Alpha', 'Bravo']);

    // Alpha into the Security folder.
    Livewire::test('pages::feeds.index')->call('sortSubscription', $subs[0]->id, 0, (string) $folder->id);
    expect($order($folder->id))->toBe(['Alpha'])
        ->and($order(null))->toBe(['Charlie', 'Bravo']);
});

it('ignores drag and drop on someone else\'s folders and feeds', function () {
    $other = User::factory()->create();
    $theirFolder = $other->folders()->create(['name' => 'Theirs']);
    $theirSub = $other->subscriptions()->create(['feed_id' => Feed::factory()->create()->id]);
    $mySub = $this->user->subscriptions()->create(['feed_id' => Feed::factory()->create()->id]);

    Livewire::test('pages::feeds.index')
        ->call('sortSubscription', $theirSub->id, 0, '0')
        ->call('sortSubscription', $mySub->id, 0, (string) $theirFolder->id);

    expect($theirSub->fresh()->folder_id)->toBeNull()
        ->and($mySub->fresh()->folder_id)->toBeNull();
});

it('adds new subscriptions at the end of their folder', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(feedFixture('atom.xml'))]);
    $first = $this->user->subscriptions()->create(['feed_id' => Feed::factory()->create(['title' => 'Zulu'])->id, 'position' => 0]);

    app(SubscribeToFeed::class)->handle($this->user, 'https://blog.example.com/atom.xml', null, 'Alpha');

    $order = Organizer::sortSubscriptions($this->user->subscriptions()->with('feed')->get())->map->displayTitle()->all();
    expect($order)->toBe(['Zulu', 'Alpha']);
    Queue::assertPushed(FetchFeed::class);
});

// --- Tags ---

it('tags stories from the reader and lists them by tag', function () {
    $feed = Feed::factory()->create();
    $this->user->subscriptions()->create(['feed_id' => $feed->id]);
    $story = story($feed, 'Patch now');
    story($feed, 'Unrelated');

    $component = Livewire::test('pages::reader.index')
        ->call('select', $story->id)
        ->call('addTag', 'Patch Tuesday')
        ->assertDispatched('tags-changed')
        ->assertSee('#Patch Tuesday');

    $tag = $this->user->tags()->sole();
    expect($tag->entries()->pluck('entries.id')->all())->toBe([$story->id]);

    // Re-adding the same name (any case) reuses the tag.
    $component->call('addTag', 'patch tuesday');
    expect($this->user->tags()->count())->toBe(1);

    Livewire::withQueryParams(['s' => 'tag:'.$tag->id, 'show' => 'all'])
        ->test('pages::reader.index')
        ->assertSee('#Patch Tuesday')
        ->assertSee('Patch now')
        ->assertDontSee('Unrelated');

    $component->call('toggleTag', $tag->id);
    expect($tag->entries()->count())->toBe(0);
});

it('keeps tagged stories readable after unsubscribing', function () {
    $feed = Feed::factory()->create();
    $sub = $this->user->subscriptions()->create(['feed_id' => $feed->id]);
    $story = story($feed, 'Keep me');

    Livewire::test('pages::reader.index')->call('select', $story->id)->call('addTag', 'archive');
    $sub->delete();

    expect(app(EntryQuery::class)->find($this->user, $story->id))->not->toBeNull();
});

it('cannot use another user\'s tags', function () {
    $feed = Feed::factory()->create();
    $this->user->subscriptions()->create(['feed_id' => $feed->id]);
    $story = story($feed, 'Mine');
    $theirTag = User::factory()->create()->tags()->create(['name' => 'theirs']);

    Livewire::test('pages::reader.index')->call('toggleTag', $theirTag->id, $story->id)->assertNotFound();
    expect($theirTag->entries()->count())->toBe(0);

    Livewire::withQueryParams(['s' => 'tag:'.$theirTag->id])->test('pages::reader.index')->assertSet('source', 'all');
});

it('manages tags on the feeds page', function () {
    Livewire::test('pages::feeds.index')
        ->set('newTagName', 'cve')
        ->call('createTag')
        ->assertSee('cve');

    $tag = $this->user->tags()->sole();

    Livewire::test('pages::feeds.index')
        ->call('renameTag', $tag->id, 'vulns')
        ->call('recolorTag', $tag->id, 'red')
        ->call('recolorTag', $tag->id, 'not-a-color');

    expect($tag->fresh()->only('name', 'color'))->toBe(['name' => 'vulns', 'color' => 'red']);

    Livewire::test('pages::feeds.index')->call('deleteTag', $tag->id);
    expect($this->user->tags()->count())->toBe(0);
});

it('shows tags with counts in the sidebar', function () {
    $tag = $this->user->tags()->create(['name' => 'ransomware', 'color' => 'red']);

    Livewire::test('reader.nav')->assertSee('Tags')->assertSee('ransomware');
});

// --- Search and filters ---

it('searches titles and article text, including read stories', function () {
    $feed = Feed::factory()->create();
    $this->user->subscriptions()->create(['feed_id' => $feed->id]);
    $read = story($feed, 'Old patch notes', ['content' => '<p>Mentions Kubernetes deep in the text.</p>']);
    story($feed, 'Something else');
    app(EntryStates::class)->markRead($this->user, [$read->id]);

    Livewire::test('pages::reader.index')
        ->set('search', 'kubernetes')
        ->assertSee('Old patch notes')
        ->assertDontSee('Something else')
        ->assertSee('Searching all stories');
});

it('filters by time period', function () {
    $feed = Feed::factory()->create();
    $this->user->subscriptions()->create(['feed_id' => $feed->id]);
    story($feed, 'Today story', ['published_at' => now()->subHours(2)]);
    story($feed, 'Last week story', ['published_at' => now()->subDays(5)]);
    story($feed, 'Last month story', ['published_at' => now()->subDays(20)]);

    $titles = fn (?string $period) => app(EntryQuery::class)
        ->for($this->user, ReaderSource::all(), false, null, [], $period)->pluck('title')->all();

    expect($titles('day'))->toBe(['Today story'])
        ->and($titles('week'))->toBe(['Today story', 'Last week story'])
        ->and($titles('month'))->toHaveCount(3)
        ->and($titles(null))->toHaveCount(3);

    $reader = Livewire::test('pages::reader.index')
        ->set('show', 'all')
        ->set('period', 'day');

    expect(listedTitles($reader))->toContain('Today story')->not->toContain('Last week story');
});

it('uses the PostgreSQL full-text index with web search syntax', function () {
    $feed = Feed::factory()->create();
    $this->user->subscriptions()->create(['feed_id' => $feed->id]);
    story($feed, 'Critical Fortinet flaw exploited', ['summary' => 'Attackers exploit FortiOS SSL-VPN.']);
    story($feed, 'Fortinet earnings call', ['summary' => 'Quarterly results.']);
    story($feed, 'Ivanti zero-day', ['content' => '<p>The <b>exploited</b> bug affects Connect Secure.</p>']);

    $search = fn (string $q) => app(EntryQuery::class)->for($this->user, ReaderSource::all(), false, $q)->pluck('title')->sort()->values()->all();

    expect($search('fortinet'))->toBe(['Critical Fortinet flaw exploited', 'Fortinet earnings call'])
        ->and($search('fortinet -earnings'))->toBe(['Critical Fortinet flaw exploited'])
        ->and($search('exploited'))->toBe(['Critical Fortinet flaw exploited', 'Ivanti zero-day'])
        ->and($search('"connect secure"'))->toBe(['Ivanti zero-day'])
        ->and($search('ivanti OR earnings'))->toBe(['Fortinet earnings call', 'Ivanti zero-day']);
})->skip(fn () => DB::connection()->getDriverName() !== 'pgsql', 'Requires PostgreSQL');
