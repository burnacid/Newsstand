<?php

use App\Jobs\FetchFeed;
use App\Models\Entry;
use App\Models\EntryNote;
use App\Models\Feed;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

it('dispatches only due feeds that have subscribers', function () {
    Queue::fake();
    $user = User::factory()->create();

    $never = Feed::factory()->create();
    $due = Feed::factory()->create();
    $due->forceFill(['next_fetch_at' => now()->subMinute()])->save();
    $later = Feed::factory()->create();
    $later->forceFill(['next_fetch_at' => now()->addHour()])->save();
    $orphan = Feed::factory()->create();

    foreach ([$never, $due, $later] as $feed) {
        $user->subscriptions()->create(['feed_id' => $feed->id]);
    }

    $this->artisan('feeds:dispatch-due')->assertSuccessful();

    Queue::assertPushed(FetchFeed::class, 2);
    Queue::assertPushed(FetchFeed::class, fn ($job) => $job->feed->is($never));
    Queue::assertPushed(FetchFeed::class, fn ($job) => $job->feed->is($due));
});

it('prunes old entries but keeps starred and saved ones', function () {
    $user = User::factory()->create();
    $feed = Feed::factory()->create();

    $make = fn (string $guid, $date) => $feed->entries()->create([
        'guid' => $guid, 'guid_hash' => sha1($guid), 'title' => $guid, 'published_at' => $date,
    ]);

    $old = $make('old', now()->subDays(200));
    $starred = $make('starred', now()->subDays(200));
    $saved = $make('saved', now()->subDays(200));
    $read = $make('read-only', now()->subDays(200));
    $recent = $make('recent', now()->subDay());

    $user->entryStates()->attach($starred->id, ['starred_at' => now()]);
    $user->entryStates()->attach($saved->id, ['read_later_at' => now()]);
    $user->entryStates()->attach($read->id, ['read_at' => now()]);

    $this->artisan('entries:prune')->assertSuccessful();

    expect(Entry::pluck('guid')->sort()->values()->all())->toBe(['recent', 'saved', 'starred']);
});

it('keeps old entries that are tagged or have a note when pruning', function () {
    $user = User::factory()->create();
    $feed = Feed::factory()->create();

    $make = fn (string $guid) => $feed->entries()->create([
        'guid' => $guid, 'guid_hash' => sha1($guid), 'title' => $guid, 'published_at' => now()->subDays(200),
    ]);

    $tagged = $make('tagged');
    $noted = $make('noted');
    $make('plain');

    $tag = $user->tags()->create(['name' => 'Keep']);
    $tagged->tags()->attach($tag->id);
    (new EntryNote(['entry_id' => $noted->id, 'body' => 'Look into this']))->forceFill(['user_id' => $user->id])->save();

    $this->artisan('entries:prune')->assertSuccessful();

    expect(Entry::pluck('guid')->sort()->values()->all())->toBe(['noted', 'tagged']);
});
