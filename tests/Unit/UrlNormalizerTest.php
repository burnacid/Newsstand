<?php

use App\Support\UrlNormalizer;

it('normalizes trivially different URLs to the same value', function (string $input) {
    expect(UrlNormalizer::normalize($input))->toBe('https://example.com/feed?a=1&b=2');
})->with([
    'https://example.com/feed?a=1&b=2',
    'HTTPS://Example.COM:443/feed?b=2&a=1',
    'https://example.com/feed?a=1&b=2&utm_source=rss#top',
    'example.com/feed?a=1&b=2',
]);

it('defaults to a root path and keeps non-default ports', function () {
    expect(UrlNormalizer::normalize('http://example.com'))->toBe('http://example.com/')
        ->and(UrlNormalizer::normalize('http://example.com:8080/x'))->toBe('http://example.com:8080/x');
});

it('resolves relative URLs against a base', function () {
    $base = 'https://blog.example.com/posts/index.html';

    expect(UrlNormalizer::resolve('/feed.xml', $base))->toBe('https://blog.example.com/feed.xml')
        ->and(UrlNormalizer::resolve('rss.xml', $base))->toBe('https://blog.example.com/posts/rss.xml')
        ->and(UrlNormalizer::resolve('//cdn.example.com/a', $base))->toBe('https://cdn.example.com/a')
        ->and(UrlNormalizer::resolve('https://other.test/x', $base))->toBe('https://other.test/x');
});
