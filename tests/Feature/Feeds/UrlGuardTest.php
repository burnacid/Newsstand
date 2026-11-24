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

it('rejects ranges PHP does not flag as private on its own', function (string $url) {
    UrlGuard::assertSafe($url);
})->throws(UnsafeUrlException::class)->with([
    'carrier-grade NAT' => 'http://100.64.1.1/feed',
    'benchmarking' => 'http://198.18.0.1/feed',
    'this network' => 'http://0.1.2.3/feed',
    'IPv4-mapped loopback' => 'http://[::ffff:127.0.0.1]/feed',
    'IPv4-mapped metadata' => 'http://[::ffff:a9fe:a9fe]/feed',
    'NAT64 loopback' => 'http://[64:ff9b::7f00:1]/feed',
    'unique local' => 'http://[fd00::1]/feed',
    'link local' => 'http://[fe80::1]/feed',
]);

it('treats public addresses as public', function (string $ip) {
    expect(UrlGuard::isPublic($ip))->toBeTrue();
})->with(['8.8.8.8', '1.1.1.1', '2606:4700:4700::1111', '::ffff:8.8.8.8']);

it('returns the addresses it checked', function () {
    expect(UrlGuard::assertSafe('https://8.8.8.8/feed'))->toBe(['8.8.8.8']);
});
