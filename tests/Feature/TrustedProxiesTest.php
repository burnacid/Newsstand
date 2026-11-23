<?php

use App\Providers\AppServiceProvider;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

afterEach(function () {
    TrustProxies::flushState();
});

function bootTrustedProxies(?string $value): void
{
    config(['app.trusted_proxies' => $value]);
    (new AppServiceProvider(app()))->boot();

    Route::get('/__trusted-proxy-probe', fn (Request $request) => $request->ip());
}

it('ignores forwarded headers when no proxies are configured', function () {
    bootTrustedProxies(null);

    $this->get('/__trusted-proxy-probe', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertSee('127.0.0.1');
});

it('trusts any proxy when configured with a wildcard', function () {
    bootTrustedProxies('*');

    $this->get('/__trusted-proxy-probe', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertSee('203.0.113.7');
});

it('trusts a comma-separated list of proxies from config', function () {
    bootTrustedProxies('10.0.0.1, 127.0.0.1');

    $this->get('/__trusted-proxy-probe', ['X-Forwarded-For' => '203.0.113.7'])
        ->assertSee('203.0.113.7');
});
