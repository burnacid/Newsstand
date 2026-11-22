<?php

use App\Jobs\FetchFeed;
use App\Models\Feed;
use App\Models\User;
use App\Services\Feeds\OpmlService;
use Illuminate\Support\Facades\Queue;

beforeEach(fn () => Queue::fake());

it('imports subscriptions into folders', function () {
    $user = User::factory()->create();

    $stats = app(OpmlService::class)->import($user, feedFixture('subscriptions.opml'));

    expect($stats)->toBe(['subscribed' => 3, 'folders' => 1, 'skipped' => 1]);

    $security = $user->folders()->where('name', 'Security')->first();
    expect($security)->not->toBeNull()
        ->and($security->subscriptions()->count())->toBe(2)
        ->and($user->subscriptions()->whereNull('folder_id')->count())->toBe(1);

    expect(Feed::findByUrl('https://krebsonsecurity.com/feed/')->title)->toBe('Krebs on Security');
    Queue::assertPushed(FetchFeed::class, 3);
});

it('reuses existing feeds and is idempotent', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();

    app(OpmlService::class)->import($other, feedFixture('subscriptions.opml'));
    app(OpmlService::class)->import($user, feedFixture('subscriptions.opml'));
    app(OpmlService::class)->import($user, feedFixture('subscriptions.opml'));

    expect(Feed::count())->toBe(3)
        ->and($user->subscriptions()->count())->toBe(3);
});

it('rejects invalid files', function () {
    app(OpmlService::class)->import(User::factory()->create(), 'not xml');
})->throws(InvalidArgumentException::class);

it('exports subscriptions as OPML', function () {
    $user = User::factory()->create();
    app(OpmlService::class)->import($user, feedFixture('subscriptions.opml'));

    $this->actingAs($user)->get(route('feeds.export'))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/x-opml; charset=UTF-8')
        ->assertSee('xmlUrl="https://krebsonsecurity.com/feed/"', false)
        ->assertSee('<outline text="Security"', false);
});

it('round-trips an export back into an import', function () {
    $user = User::factory()->create();
    app(OpmlService::class)->import($user, feedFixture('subscriptions.opml'));
    $xml = app(OpmlService::class)->export($user);

    $fresh = User::factory()->create();
    $stats = app(OpmlService::class)->import($fresh, $xml);

    expect($stats['subscribed'])->toBe(3)
        ->and($fresh->folders()->pluck('name')->all())->toBe(['Security']);
});
