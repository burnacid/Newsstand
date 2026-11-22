<?php

use App\Services\Feeds\FeedDiscovery;
use Illuminate\Support\Facades\Http;

it('returns the URL itself when it is a feed', function () {
    Http::fake(['https://blog.example.com/atom.xml' => Http::response(feedFixture('atom.xml'))]);

    $found = app(FeedDiscovery::class)->discover('blog.example.com/atom.xml');

    expect($found)->toHaveCount(1)
        ->and($found[0]->url)->toBe('https://blog.example.com/atom.xml')
        ->and($found[0]->title)->toBe('Dev Blog');
});

it('finds alternate links in an HTML page', function () {
    Http::fake(['https://blog.example.com/' => Http::response(feedFixture('site.html'))]);

    $found = app(FeedDiscovery::class)->discover('https://blog.example.com');

    expect($found)->toHaveCount(2)
        ->and($found[0]->url)->toBe('https://blog.example.com/feed.xml')
        ->and($found[0]->title)->toBe('Posts')
        ->and($found[1]->url)->toBe('https://blog.example.com/comments.atom');
});

it('probes common feed paths when the page has no links', function () {
    Http::fake([
        'https://plain.example.com/' => Http::response('<html><head></head><body>hi</body></html>'),
        'https://plain.example.com/feed' => Http::response(feedFixture('rss.xml')),
        '*' => Http::response('', 404),
    ]);

    $found = app(FeedDiscovery::class)->discover('https://plain.example.com/');

    expect($found)->toHaveCount(1)->and($found[0]->url)->toBe('https://plain.example.com/feed');
});

it('returns nothing when no feed exists', function () {
    Http::fake([
        'https://none.example.com/' => Http::response('<html></html>'),
        '*' => Http::response('', 404),
    ]);

    expect(app(FeedDiscovery::class)->discover('https://none.example.com/'))->toBe([]);
});
