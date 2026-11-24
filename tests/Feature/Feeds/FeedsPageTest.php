<?php

use App\Jobs\FetchFeed;
use App\Models\Entry;
use App\Models\Feed;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('requires authentication', function () {
    auth()->logout();

    $this->get(route('feeds.index'))->assertRedirect(route('login'));
});

it('renders the feeds page', function () {
    $this->get(route('feeds.index'))->assertOk()->assertSee('Add a feed');
});

it('subscribes to a discovered feed', function () {
    Http::fake(['https://blog.example.com/atom.xml' => Http::response(feedFixture('atom.xml'))]);

    Livewire::test('pages::feeds.index')
        ->set('url', 'https://blog.example.com/atom.xml')
        ->call('discover')
        ->assertHasNoErrors();

    $subscription = $this->user->subscriptions()->with('feed')->sole();
    expect($subscription->feed->title)->toBe('Dev Blog');
    Queue::assertPushed(FetchFeed::class);
});

it('offers a choice when a site has several feeds', function () {
    Http::fake(['https://blog.example.com/' => Http::response(feedFixture('site.html'))]);

    Livewire::test('pages::feeds.index')
        ->set('url', 'https://blog.example.com')
        ->call('discover')
        ->assertCount('candidates', 2)
        ->call('choose', 1);

    expect($this->user->subscriptions()->sole()->feed->url)->toBe('https://blog.example.com/comments.atom');
});

it('shows an error when no feed is found', function () {
    Http::fake(['*' => Http::response('<html></html>')]);

    Livewire::test('pages::feeds.index')
        ->set('url', 'https://nothing.example.com')
        ->call('discover')
        ->assertHasErrors('url');

    expect($this->user->subscriptions()->count())->toBe(0);
});

it('lists subscriptions grouped by folder', function () {
    $folder = $this->user->folders()->create(['name' => 'Security']);
    $feed = Feed::factory()->create(['title' => 'Krebs on Security']);
    $this->user->subscriptions()->create(['feed_id' => $feed->id, 'folder_id' => $folder->id]);

    Livewire::test('pages::feeds.index')
        ->assertSee('Security')
        ->assertSee('Krebs on Security');
});

it('edits a subscription', function () {
    $folder = $this->user->folders()->create(['name' => 'Tech']);
    $feed = Feed::factory()->create();
    $subscription = $this->user->subscriptions()->create(['feed_id' => $feed->id]);

    Livewire::test('pages::feeds.index')
        ->call('edit', $subscription->id)
        ->set('editTitle', 'My title')
        ->set('editFolderId', (string) $folder->id)
        ->set('editFullText', true)
        ->call('saveEdit')
        ->assertHasNoErrors();

    $subscription->refresh();
    expect($subscription->custom_title)->toBe('My title')
        ->and($subscription->folder_id)->toBe($folder->id)
        ->and($subscription->full_text)->toBeTrue()
        ->and($feed->fresh()->full_text)->toBeTrue();
});

it('keeps full text on for the feed while any subscriber wants it', function () {
    $feed = Feed::factory()->create();
    $mine = $this->user->subscriptions()->create(['feed_id' => $feed->id]);
    $theirs = User::factory()->create()->subscriptions()->create(['feed_id' => $feed->id, 'full_text' => true]);

    expect($feed->fresh()->full_text)->toBeTrue();

    // Turning it off for me does not turn it off for them.
    Livewire::test('pages::feeds.index')
        ->call('edit', $mine->id)
        ->assertSet('editFullText', false)
        ->set('editFullText', false)
        ->call('saveEdit');
    expect($feed->fresh()->full_text)->toBeTrue();

    // Once nobody wants it, the feed stops fetching full text.
    $theirs->delete();
    expect($feed->fresh()->full_text)->toBeFalse();
});

it('unsubscribes without deleting the shared feed', function () {
    $feed = Feed::factory()->create();
    $subscription = $this->user->subscriptions()->create(['feed_id' => $feed->id]);

    Livewire::test('pages::feeds.index')->call('unsubscribe', $subscription->id);

    expect($this->user->subscriptions()->count())->toBe(0)
        ->and(Feed::find($feed->id))->not->toBeNull();
});

it('cannot touch another user\'s subscriptions or folders', function () {
    $other = User::factory()->create();
    $feed = Feed::factory()->create();
    $subscription = $other->subscriptions()->create(['feed_id' => $feed->id]);
    $folder = $other->folders()->create(['name' => 'Private']);

    Livewire::test('pages::feeds.index')->call('unsubscribe', $subscription->id)->assertNotFound();
    Livewire::test('pages::feeds.index')->call('deleteFolder', $folder->id)->assertNotFound();

    // Using someone else's folder id is a validation error.
    Livewire::test('pages::feeds.index')
        ->set('url', 'https://x.example.com')
        ->set('folderId', (string) $folder->id)
        ->call('discover')
        ->assertHasErrors('folderId');

    expect($other->subscriptions()->count())->toBe(1)->and($folder->fresh())->not->toBeNull();
});

it('manages folders', function () {
    Livewire::test('pages::feeds.index')
        ->set('folderName', 'News')
        ->call('createFolder')
        ->assertHasNoErrors()
        ->set('folderName', 'News')
        ->call('createFolder')
        ->assertHasErrors('folderName');

    $folder = $this->user->folders()->sole();
    $feed = Feed::factory()->create();
    $subscription = $this->user->subscriptions()->create(['feed_id' => $feed->id, 'folder_id' => $folder->id]);

    Livewire::test('pages::feeds.index')
        ->call('startRenameFolder', $folder->id)
        ->set('renameFolderName', 'Headlines')
        ->call('renameFolder');
    expect($folder->fresh()->name)->toBe('Headlines');

    Livewire::test('pages::feeds.index')->call('deleteFolder', $folder->id);
    expect($subscription->fresh()->folder_id)->toBeNull();
});

it('refreshes a feed on demand', function () {
    $feed = Feed::factory()->create(['url' => 'https://security.example.com/feed.xml']);
    $subscription = $this->user->subscriptions()->create(['feed_id' => $feed->id]);
    Http::fake(['*' => Http::response(feedFixture('rss.xml'))]);

    Livewire::test('pages::feeds.index')->call('refresh', $subscription->id);

    expect(Entry::where('feed_id', $feed->id)->count())->toBe(2);
});
