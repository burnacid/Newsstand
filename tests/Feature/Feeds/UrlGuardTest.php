<?php

use App\Exceptions\UnsafeUrlException;
use App\Support\UrlGuard;

beforeEach(fn () => config(['newsstand.fetch.block_private_hosts' => true]));

it('rejects non-http schemes', function (string $url) {
    UrlGuard::assertSafe($url);
})->throws(UnsafeUrlException::class)->with(['file:///etc/passwd', 'ftp://example.com/feed', 'gopher://x']);

it('rejects private and reserved addresses', function (string $url) {
    UrlGuard::assertSafe($url);
})->throws(UnsafeUrlException::class)->with([
    'http://127.0.0.1/feed',
    'http://10.0.0.5/feed',
    'http://192.168.1.10:8080/rss',
    'http://169.254.169.254/latest/meta-data',
    'http://[::1]/feed',
]);

it('allows public IP addresses', function () {
    UrlGuard::assertSafe('https://8.8.8.8/feed');
})->throwsNoExceptions();

it('allows private hosts when blocking is disabled', function () {
    config(['newsstand.fetch.block_private_hosts' => false]);

    UrlGuard::assertSafe('http://192.168.1.10/feed');
})->throwsNoExceptions();
