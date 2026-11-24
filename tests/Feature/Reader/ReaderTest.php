<?php

use App\Models\Entry;
use App\Models\Feed;
use App\Models\User;
use App\Services\Reader\EntryQuery;
use App\Services\Reader\EntryStates;
use App\Services\Reader\ReaderSource;
use App\Support\FeedTitle;
use Livewire\Livewire;

function makeEntry(Feed $feed, string $title, $published = null, array $attrs = []): Entry
{
    return $feed->entries()->create([
        'guid' => $title, 'guid_hash' => sha1($feed->id.$title), 'title' => $title,
        'content' => "<p>Body of {$title}</p>", 'published_at' => $published ?? now(), ...$attrs,
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->folder = $this->user->folders()->create(['name' => 'Security']);
    $this->security = Feed::factory()->create(['title' => 'Krebs']);
    $this->tech = Feed::factory()->create(['title' => 'Laravel News']);
    $this->user->subscriptions()->create(['feed_id' => $this->security->id, 'folder_id' => $this->folder->id]);
    $this->user->subscriptions()->create(['feed_id' => $this->tech->id]);

    $this->a = makeEntry($this->security, 'Zero-day in VPN', now()->subHour());
    $this->b = makeEntry($this->tech, 'Laravel 13 released', now()->subHours(2));
    $this->old = makeEntry($this->tech, 'Ancient news', now()->subDays(60));

    $this->other = Feed::factory()->create(['title' => 'Not subscribed']);
    $this->hidden = makeEntry($this->other, 'Secret entry');
});

it('renders the reader', function () {
    $this->get(route('reader'))->assertOk()->assertSee('All stories')->assertSee('Zero-day in VPN');
});

it('shows only unread, recent entries from subscribed feeds by default', function () {
    Livewire::test('pages::reader.index')
        ->assertSee('Zero-day in VPN')
        ->assertSee('Laravel 13 released')
        ->assertDontSee('Ancient news')
        ->assertDontSee('Secret entry')
        ->set('show', 'all')
        ->assertSee('Ancient news')
        ->assertDontSee('Secret entry');
});

it('filters by feed and folder', function () {
    Livewire::withQueryParams(['s' => 'folder:'.$this->folder->id])
        ->test('pages::reader.index')
        ->assertSee('Zero-day in VPN')
        ->assertDontSee('Laravel 13 released');

    Livewire::withQueryParams(['s' => 'feed:'.$this->tech->id])
        ->test('pages::reader.index')
        ->assertSee('Laravel 13 released')
        ->assertDontSee('Zero-day in VPN');
});

it('falls back to all stories for feeds and folders you do not own', function () {
    $foreignFolder = User::factory()->create()->folders()->create(['name' => 'Theirs']);

    Livewire::withQueryParams(['s' => 'feed:'.$this->other->id])
        ->test('pages::reader.index')
        ->assertSet('source', 'all')
        ->assertDontSee('Secret entry');

    Livewire::withQueryParams(['s' => 'folder:'.$foreignFolder->id])
        ->test('pages::reader.index')
        ->assertSet('source', 'all');
});

it('counts stories that arrive after the list loaded and pulls them in on request', function () {
    $reader = Livewire::withQueryParams(['s' => 'folder:'.$this->folder->id])->test('pages::reader.index');

    expect($reader->instance()->countNew())->toBe(0);

    makeEntry($this->security, 'Fresh breach');
    makeEntry($this->tech, 'Outside this folder');
    makeEntry($this->other, 'Not subscribed either');

    expect($reader->instance()->countNew())->toBe(1);
    $reader->assertDontSee('Fresh breach');

    $reader->call('showNew')->assertSee('Fresh breach')->assertDispatched('unread-changed');
    expect($reader->instance()->countNew())->toBe(0);
});

it('does not count new stories that are already read in unread mode', function () {
    $reader = Livewire::test('pages::reader.index');

    $entry = makeEntry($this->tech, 'Read elsewhere');
    app(EntryStates::class)->markRead($this->user, [$entry->id]);

    expect($reader->instance()->countNew())->toBe(0)
        ->and($reader->set('show', 'all')->instance()->countNew())->toBe(0); // switching filters reloads, so nothing is "new"
});

it('marks an entry read when opened but keeps it in the list', function () {
    Livewire::test('pages::reader.index')
        ->call('select', $this->a->id)
        ->assertSet('entryId', $this->a->id)
        ->assertDispatched('unread-changed')
        ->assertSee('Body of Zero-day in VPN')
        ->assertSee('Zero-day in VPN');

    expect($this->user->entryStates()->find($this->a->id)->pivot->read_at)->not->toBeNull();
});

it('refuses to open entries from feeds you are not subscribed to', function () {
    Livewire::test('pages::reader.index')
        ->call('select', $this->hidden->id)
        ->assertSet('entryId', null)
        ->assertDontSee('Body of Secret entry');

    Livewire::withQueryParams(['e' => $this->hidden->id])
        ->test('pages::reader.index')
        ->assertSet('entryId', null);
});

it('toggles read, star and read later', function () {
    $component = Livewire::test('pages::reader.index')->call('select', $this->a->id);

    $component->call('toggleStar')->call('toggleLater')->call('toggleRead');

    $state = $this->user->entryStates()->find($this->a->id)->pivot;
    expect($state->starred_at)->not->toBeNull()
        ->and($state->read_later_at)->not->toBeNull()
        ->and($state->read_at)->toBeNull();

    Livewire::withQueryParams(['s' => 'starred'])->test('pages::reader.index')
        ->assertSee('Zero-day in VPN')->assertDontSee('Laravel 13 released');
    Livewire::withQueryParams(['s' => 'later'])->test('pages::reader.index')
        ->assertSee('Zero-day in VPN');
});

it('keeps starred entries readable after unsubscribing', function () {
    Livewire::test('pages::reader.index')->call('select', $this->a->id)->call('toggleStar');
    $this->user->subscriptions()->where('feed_id', $this->security->id)->delete();

    Livewire::withQueryParams(['s' => 'starred'])->test('pages::reader.index')
        ->call('select', $this->a->id)
        ->assertSet('entryId', $this->a->id);
});

it('marks everything in the current view as read', function () {
    Livewire::withQueryParams(['s' => 'feed:'.$this->tech->id])
        ->test('pages::reader.index')
        ->call('markAllRead')
        ->assertDispatched('unread-changed');

    $counts = app(EntryQuery::class)->unreadCounts($this->user);
    expect($counts[$this->tech->id] ?? 0)->toBe(0)
        ->and($counts[$this->security->id])->toBe(1);
});

it('searches titles', function () {
    $reader = Livewire::test('pages::reader.index')->set('search', 'laravel');

    expect(listedTitles($reader))->toBe(['Laravel 13 released']);
});

it('loads more entries', function () {
    config(['newsstand.reader_page_size' => 2]);
    makeEntry($this->tech, 'Third story', now()->subHours(3));

    Livewire::test('pages::reader.index')
        ->assertDontSee('Third story')
        ->call('loadMore')
        ->assertSee('Third story');
});

it('keeps read state per user', function () {
    $other = User::factory()->create();
    $other->subscriptions()->create(['feed_id' => $this->security->id]);

    Livewire::test('pages::reader.index')->call('select', $this->a->id);

    expect(app(EntryQuery::class)->unreadCounts($other)[$this->security->id])->toBe(1)
        ->and(app(EntryQuery::class)->unreadCounts($this->user)[$this->security->id] ?? 0)->toBe(0);
});

it('shows story previews, not full articles, in the river view', function () {
    $this->a->update(['summary' => 'Short summary of the VPN flaw.']);

    Livewire::test('pages::reader.index')
        ->call('setLayout', 'river')
        ->assertSet('layout', 'river')
        ->assertSee('Zero-day in VPN')
        ->assertSee('Short summary of the VPN flaw.')
        ->assertDontSee('Body of Zero-day in VPN')
        ->assertSee('Continue reading')
        ->assertSeeHtml('data-river-id="'.$this->a->id.'"');
});

it('opens the full text inline in the river and counts it as read', function () {
    $component = Livewire::test('pages::reader.index')
        ->call('setLayout', 'river')
        ->call('expand', $this->a->id)
        ->assertSee('Body of Zero-day in VPN')
        ->assertDontSee('Body of Laravel 13 released')
        ->assertDispatched('unread-changed');

    expect($this->user->entryStates()->find($this->a->id)->pivot->read_at)->not->toBeNull();

    $component->call('expand', $this->a->id)->assertDontSee('Body of Zero-day in VPN');

    // Can't expand entries you can't see.
    $component->call('expand', $this->hidden->id)->assertDontSee('Body of Secret entry');
});

it('marks entries read when scrolled past in the river', function () {
    $component = Livewire::test('pages::reader.index')
        ->call('setLayout', 'river')
        ->call('markSeen', [$this->a->id, $this->b->id])
        ->assertDispatched('unread-changed');

    expect(app(EntryQuery::class)->unreadCounts($this->user)->sum())->toBe(0);

    // They stay in the unread river until you leave the page.
    $component->call('loadMore')->assertSee('Zero-day in VPN');
});

it('only marks visible entries when scrolling', function () {
    Livewire::withQueryParams(['s' => 'feed:'.$this->tech->id])
        ->test('pages::reader.index')
        ->call('setLayout', 'river')
        ->call('markSeen', [$this->a->id, $this->hidden->id, 'nonsense']);

    // a is in another feed than the one being viewed; hidden isn't subscribed.
    expect($this->user->entryStates()->count())->toBe(0);
});

it('does not dispatch when nothing new was seen', function () {
    Livewire::test('pages::reader.index')
        ->call('setLayout', 'river')
        ->call('markSeen', [])
        ->assertNotDispatched('unread-changed');
});

it('counts unread entries for the sidebar', function () {
    Livewire::test('reader.nav')
        ->assertSee('All stories')
        ->assertSee('Krebs')
        ->assertSee('Laravel News')
        ->assertSee('Security');
});

it('parses reader sources', function () {
    expect(ReaderSource::parse('feed:12')->key())->toBe('feed:12')
        ->and(ReaderSource::parse('folder:3')->type)->toBe('folder')
        ->and(ReaderSource::parse('starred')->isCollection())->toBeTrue()
        ->and(ReaderSource::parse('nonsense')->key())->toBe('all')
        ->and(ReaderSource::parse(null)->key())->toBe('all');
});

it('cleans feed titles', function (string $raw, string $clean) {
    expect(FeedTitle::clean($raw))->toBe($clean);
})->with([
    ['Krebs on Security » Feed', 'Krebs on Security'],
    ['Laravel News » Feed', 'Laravel News'],
    ['Example Blog - RSS', 'Example Blog'],
    ['RSS: Dev Notes', 'Dev Notes'],
    ['The Hacker News', 'The Hacker News'],
    ['Feedbin', 'Feedbin'],
]);

it('marks only stories older than a day or a week as read', function () {
    $lastWeek = makeEntry($this->security, 'Patch Tuesday recap', now()->subDays(3));

    Livewire::test('pages::reader.index')->call('markAllRead', 'day');

    $read = fn (Entry $e) => $this->user->entryStates()->find($e->id)?->pivot->read_at !== null;
    expect($read($lastWeek))->toBeTrue()
        ->and($read($this->a))->toBeFalse()
        ->and($read($this->b))->toBeFalse();
});

it('sorts oldest first and remembers it', function () {
    $component = Livewire::test('pages::reader.index')->set('show', 'all');
    expect($component->instance()->entries->pluck('title')->first())->toBe('Zero-day in VPN');

    $component->call('toggleSort')->assertSet('sort', 'oldest');
    expect($component->instance()->entries->pluck('title')->first())->toBe('Ancient news');

    $component->call('toggleSort')->assertSet('sort', 'newest');
});

it('saves a search to the sidebar and removes it again', function () {
    $reader = Livewire::withQueryParams(['s' => 'folder:'.$this->folder->id])->test('pages::reader.index')
        ->set('search', 'zero-day')
        ->assertDispatched('reader-search-changed')
        ->call('toggleSavedSearch')
        ->assertDispatched('saved-searches-changed');

    $saved = $this->user->savedSearches()->sole();
    expect($saved->query)->toBe('zero-day')
        ->and($saved->source)->toBe('folder:'.$this->folder->id)
        ->and($saved->url())->toContain('q=zero-day');

    Livewire::test('reader.nav')->assertSee('zero-day');

    $reader->call('toggleSavedSearch');
    expect($this->user->savedSearches()->count())->toBe(0);
});

it('keeps saved searches private', function () {
    User::factory()->create()->savedSearches()->create(['name' => 'their search', 'source' => 'all', 'query' => 'secret']);

    Livewire::test('reader.nav')->assertDontSee('their search');
});

it('redraws only the rows that changed', function () {
    $reader = Livewire::test('pages::reader.index')
        ->assertSeeHtml('data-entry-id="'.$this->a->id.'"')
        ->assertSeeHtml('data-entry-id="'.$this->b->id.'"');

    // Opening story a changes its row (selected, read); story b's row is skipped.
    $reader->call('select', $this->a->id)
        ->assertSeeHtml('data-entry-id="'.$this->a->id.'"')
        ->assertDontSeeHtml('data-entry-id="'.$this->b->id.'"');
});

it('tags a story from its river row without re-rendering the reader', function () {
    $tag = $this->user->tags()->create(['name' => 'vpn']);
    $entry = app(EntryQuery::class)->find($this->user, $this->a->id);

    Livewire::test('reader.row', ['entry' => $entry, 'layout' => 'river'])
        ->assertSee('Zero-day in VPN')
        ->call('toggleTag', $tag->id)
        ->assertDispatched('tags-changed')
        ->assertSee('#vpn');

    expect($tag->entries()->pluck('entries.id')->all())->toBe([$this->a->id]);
});
