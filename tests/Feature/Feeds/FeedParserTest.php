<?php

use App\Exceptions\InvalidFeedException;
use App\Services\Feeds\FeedParser;

it('parses an RSS feed', function () {
    $feed = (new FeedParser)->parse(feedFixture('rss.xml'));

    expect($feed->title)->toBe('Security Weekly & More')
        ->and($feed->siteUrl)->toBe('https://security.example.com/')
        ->and($feed->items)->toHaveCount(2);

    $first = $feed->items[0];
    expect($first->guid)->toBe('post-1')
        ->and($first->title)->toBe('Critical flaw in Example VPN (CVE-2026-12345)')
        ->and($first->author)->toBe('Jane Doe')
        ->and($first->publishedAt->toIso8601String())->toBe('2026-11-20T10:00:00+00:00')
        ->and($first->summary)->toContain('A critical bug.')
        ->and($first->imageUrl)->toBe('https://cdn.example.com/vpn.png');

    expect($feed->items[1]->imageUrl)->toBe('https://cdn.example.com/patch.jpg');
});

it('parses an Atom feed', function () {
    $feed = (new FeedParser)->parse(feedFixture('atom.xml'));

    expect($feed->title)->toBe('Dev Blog')
        ->and($feed->items)->toHaveCount(1)
        ->and($feed->items[0]->url)->toBe('https://blog.example.com/laravel-13')
        ->and($feed->items[0]->author)->toBe('Taylor')
        ->and($feed->items[0]->content)->toContain('<em>content</em>');
});

it('rejects HTML pages', function () {
    (new FeedParser)->parse(feedFixture('site.html'));
})->throws(InvalidFeedException::class);
