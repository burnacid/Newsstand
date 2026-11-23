<?php

use App\Models\User;

it('serves the service worker with the release version', function () {
    config(['newsstand.version' => '9.8.7']);

    $response = $this->get('/sw.js');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/javascript; charset=utf-8')
        ->assertHeader('Service-Worker-Allowed', '/')
        ->assertSee("const VERSION = '9.8.7';", false)
        ->assertDontSee('__NEWSSTAND_VERSION__');

    expect($response->headers->get('Cache-Control'))->toContain('no-cache');
});

it('serves the service worker without starting a session', function () {
    $response = $this->get('/sw.js');

    expect(collect($response->headers->getCookies())->map->getName()->all())->toBe([]);
});

it('serves the service worker to signed-in users too', function () {
    $this->actingAs(User::factory()->create())->get('/sw.js')->assertOk();
});

it('never caches pages in the service worker', function () {
    $script = file_get_contents(resource_path('pwa/sw.js'));

    // Pages come from the network with the offline page as fallback; only static assets are cached.
    expect($script)->toContain("request.mode === 'navigate'")
        ->and($script)->toContain("url.pathname.startsWith('/build/')")
        ->and($script)->not->toContain('cache.put(request, response)');
});

it('has an installable web app manifest', function () {
    $manifest = json_decode(file_get_contents(public_path('site.webmanifest')), true, flags: JSON_THROW_ON_ERROR);

    expect($manifest)->toHaveKeys(['id', 'name', 'short_name', 'start_url', 'scope', 'display', 'icons'])
        ->and($manifest['start_url'])->toBe('/reader')
        ->and($manifest['display'])->toBe('standalone');

    $sizes = collect($manifest['icons'])->pluck('sizes')->all();
    expect($sizes)->toContain('192x192')->toContain('512x512')
        ->and(collect($manifest['icons'])->pluck('purpose')->all())->toContain('maskable');

    foreach ([...$manifest['icons'], ...collect($manifest['shortcuts'])->flatMap(fn ($s) => $s['icons'])->all()] as $icon) {
        expect(public_path(ltrim($icon['src'], '/')))->toBeFile();
    }
});

it('ships an offline page and the files the worker precaches', function () {
    expect(public_path('offline.html'))->toBeFile()
        ->and(file_get_contents(public_path('offline.html')))->toContain("You're offline");

    preg_match('/const PRECACHE = \[(.*?)\];/', file_get_contents(resource_path('pwa/sw.js')), $match);
    preg_match_all("/'([^']+)'/", str_replace('OFFLINE_URL', "'/offline.html'", $match[1]), $urls);

    expect($urls[1])->not->toBeEmpty();
    foreach ($urls[1] as $url) {
        expect(public_path(ltrim($url, '/')))->toBeFile();
    }
});

it('links the manifest from every page', function () {
    $this->get(route('home'))
        ->assertSee('<link rel="manifest" href="/site.webmanifest">', false)
        ->assertSee('apple-mobile-web-app-capable', false);
});
