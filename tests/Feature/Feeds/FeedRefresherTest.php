<?php

use App\Jobs\ExtractFullText;
use App\Models\Feed;
use App\Models\User;
use App\Services\Feeds\FeedRefresher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->feed = Feed::factory()->create(['url' => 'https://security.example.com/feed.xml', 'title' => null]);
    User::factory()->create()->subscriptions()->create(['feed_id' => $this->feed->id]);
});

function refreshFeed(Feed $feed): int
{
    return app(FeedRefresher::class)->refresh($feed->fresh());
}

it('stores entries and feed metadata', function () {
    Http::fake(['*' => Http::response(feedFixture('rss.xml'), 200, ['ETag' => '"v1"'])]);

    expect(refreshFeed($this->feed))->toBe(2);

    $feed = $this->feed->fresh();
    expect($feed->title)->toBe('Security Weekly & More')
        ->and($feed->site_url)->toBe('https://security.example.com/')
        ->and($feed->etag)->toBe('"v1"')
        ->and($feed->error_count)->toBe(0)
        ->and($feed->next_fetch_at)->not->toBeNull()
        ->and($feed->entries()->count())->toBe(2);
});

it('sanitizes article HTML', function () {
    Http::fake(['*' => Http::response(feedFixture('rss.xml'))]);
    refreshFeed($this->feed);

    $content = $this->feed->entries()->where('guid', 'post-1')->value('content');

    expect($content)->toContain('<strong>critical</strong>')
        ->not->toContain('<script')
        ->not->toContain('onclick')
        ->not->toContain('onerror')
        ->toContain('rel="noopener noreferrer nofollow"');
});

it('does not duplicate entries on a second fetch', function () {
    Http::fake(['*' => Http::response(feedFixture('rss.xml'))]);

    refreshFeed($this->feed);
    expect(refreshFeed($this->feed))->toBe(0)
        ->and($this->feed->entries()->count())->toBe(2);
});

it('sends conditional headers and handles 304', function () {
    $this->feed->forceFill(['etag' => '"v1"', 'last_modified' => 'Fri, 20 Nov 2026 10:00:00 GMT'])->save();
    Http::fake(['*' => Http::response('', 304)]);

    expect(refreshFeed($this->feed))->toBe(0);

    Http::assertSent(fn (Request $r) => $r->header('If-None-Match') === ['"v1"']
        && $r->header('If-Modified-Since') === ['Fri, 20 Nov 2026 10:00:00 GMT']);

    $feed = $this->feed->fresh();
    expect($feed->error_count)->toBe(0)->and($feed->etag)->toBe('"v1"');
});

it('polls busy feeds often and quiet feeds rarely', function () {
    Http::fake(['*' => Http::response('', 304)]);

    // 15 posts over the last 7 hours: one every 30 minutes -> poll every 15.
    foreach (range(0, 14) as $i) {
        $this->feed->entries()->create([
            'guid' => "busy-{$i}", 'guid_hash' => sha1("busy-{$i}"), 'published_at' => now()->subMinutes(30 * $i),
        ]);
    }
    refreshFeed($this->feed);
    expect($this->feed->fresh()->fetch_interval)->toBe(15);

    // No recent posts -> max interval.
    $this->feed->entries()->delete();
    refreshFeed($this->feed);
    expect($this->feed->fresh()->fetch_interval)->toBe(360);
});

it('ignores night and weekend gaps for feeds that post during office hours', function () {
    Http::fake(['*' => Http::response('', 304)]);
    $this->travelTo(now()->next('Monday')->setTime(10, 0));

    // Every 48 minutes from 9:00 to 17:00 (11 posts a day) on Thursday, Friday and Monday morning.
    $times = collect(['Thursday', 'Friday'])
        ->flatMap(fn ($day) => collect(range(0, 10))->map(fn ($i) => now()->previous($day)->setTime(9, 0)->addMinutes(48 * $i)))
        ->push(now()->setTime(9, 0), now()->setTime(9, 48));

    foreach ($times as $i => $at) {
        $this->feed->entries()->create(['guid' => "office-{$i}", 'guid_hash' => sha1("office-{$i}"), 'published_at' => $at]);
    }

    refreshFeed($this->feed);

    // Half the real 48-minute rhythm; the average (weekend included) gave 148.
    expect($this->feed->fresh()->fetch_interval)->toBe(24);
});

it('treats a batch of posts with one timestamp as a single post', function () {
    Http::fake(['*' => Http::response('', 304)]);

    // A daily digest: 5 items at once, for 4 days -> poll at half a day (capped at 6 hours), not every 15 minutes.
    foreach (range(0, 3) as $day) {
        foreach (range(0, 4) as $item) {
            $this->feed->entries()->create([
                'guid' => "d{$day}-{$item}", 'guid_hash' => sha1("d{$day}-{$item}"), 'published_at' => now()->subDays($day)->startOfMinute(),
            ]);
        }
    }

    refreshFeed($this->feed);

    expect($this->feed->fresh()->fetch_interval)->toBe(360);
});

it('records errors and backs off', function () {
    Http::fake(['*' => Http::response('nope', 500)]);

    refreshFeed($this->feed);
    $first = $this->feed->fresh();
    refreshFeed($this->feed);
    $second = $this->feed->fresh();

    expect($second->error_count)->toBe(2)
        ->and($second->last_error)->toContain('HTTP 500')
        ->and($second->next_fetch_at->diffInMinutes($second->last_fetched_at, true))
        ->toBeGreaterThan($first->next_fetch_at->diffInMinutes($first->last_fetched_at, true));
});

it('records an error for responses that are not feeds', function () {
    Http::fake(['*' => Http::response(feedFixture('site.html'))]);

    refreshFeed($this->feed);

    expect($this->feed->fresh()->last_error)->toContain('not an RSS or Atom feed');
});

it('queues full-text extraction for new entries when enabled', function () {
    Queue::fake();
    $this->feed->subscriptions()->first()->update(['full_text' => true]);
    Http::fake(['*' => Http::response(feedFixture('rss.xml'))]);

    refreshFeed($this->feed);

    Queue::assertPushed(ExtractFullText::class, 2);
});

it('honors Retry-After when rate limited', function () {
    Http::fake(['*' => Http::response('slow down', 429, ['Retry-After' => '7200'])]);

    refreshFeed($this->feed);

    expect(now()->diffInMinutes($this->feed->fresh()->next_fetch_at))->toBeGreaterThanOrEqual(119.0);
});

it('checks feeds that are gone only once a day', function () {
    Http::fake(['*' => Http::response('', 410)]);

    refreshFeed($this->feed);

    expect(now()->diffInMinutes($this->feed->fresh()->next_fetch_at))->toBeGreaterThanOrEqual(24 * 60 - 1.0);
});

it('stores the new address of a feed that moved permanently', function () {
    Http::fake(['*' => Http::response(feedFixture('rss.xml'), 200, [
        'X-Guzzle-Redirect-History' => 'https://new.example.com/feed.xml',
        'X-Guzzle-Redirect-Status-History' => '301',
    ])]);

    refreshFeed($this->feed);

    expect($this->feed->fresh()->url)->toBe('https://new.example.com/feed.xml');
});

it('keeps the address after a temporary redirect or when the new address is taken', function (string $status, bool $taken) {
    if ($taken) {
        Feed::factory()->create(['url' => 'https://new.example.com/feed.xml']);
    }
    Http::fake(['*' => Http::response(feedFixture('rss.xml'), 200, [
        'X-Guzzle-Redirect-History' => 'https://new.example.com/feed.xml',
        'X-Guzzle-Redirect-Status-History' => $status,
    ])]);

    refreshFeed($this->feed);

    expect($this->feed->fresh()->url)->toBe('https://security.example.com/feed.xml');
})->with([
    'temporary' => ['302', false],
    'mixed hops' => ['301, 302', false],
    'taken' => ['301', true],
]);
