<?php

use App\Models\Setting;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\Event;

it('shows the welcome page', function () {
    $this->get(route('home'))->assertOk();
});

it('reports healthy on /up when the database and cache answer', function () {
    $this->get('/up')->assertOk();
});

it('reports unhealthy on /up when a check fails', function () {
    Event::listen(DiagnosingHealth::class, fn () => throw new RuntimeException('database is down'));

    $this->withoutExceptionHandling([RuntimeException::class])->get('/up')->assertServerError();
})->skip(fn () => ! class_exists(DiagnosingHealth::class), 'No health diagnostics in this Laravel version');

it('sends baseline security headers', function () {
    $this->get(route('home'))
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin')
        ->assertHeaderMissing('Strict-Transport-Security');
});

it('sends HSTS over https only', function () {
    $this->get('https://localhost/')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('checks that the scheduler ran recently', function () {
    $this->artisan('scheduler:check')->assertFailed();

    Setting::set('scheduler.last_run_at', now()->subMinute()->toIso8601String());
    $this->artisan('scheduler:check')->assertSuccessful();

    Setting::set('scheduler.last_run_at', now()->subMinutes(10)->toIso8601String());
    $this->artisan('scheduler:check')->assertFailed();
});
