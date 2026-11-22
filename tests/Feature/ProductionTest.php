<?php

use App\Mail\DailyDigest;
use App\Models\Cve;
use App\Models\Feed;
use App\Models\User;
use App\Services\Digest\DigestBuilder;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

// --- Registration ---

it('allows the first account even when registration is closed', function () {
    config(['newsstand.registration' => 'closed']);

    $this->get(route('register'))->assertOk();
    $this->post(route('register.store'), [
        'name' => 'Admin', 'email' => 'admin@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertRedirect();

    expect(User::count())->toBe(1);
});

it('closes registration once there is a user', function () {
    config(['newsstand.registration' => 'closed']);
    User::factory()->create();

    $this->get(route('register'))->assertNotFound();
    $this->get(route('login'))->assertDontSee('Sign up');

    // A direct POST must not bypass the closed form.
    $this->post(route('register.store'), [
        'name' => 'Intruder', 'email' => 'x@example.com', 'password' => 'password', 'password_confirmation' => 'password',
    ])->assertForbidden();

    expect(User::count())->toBe(1);
});

it('keeps registration open when configured', function () {
    config(['newsstand.registration' => 'open']);
    User::factory()->create();

    $this->get(route('register'))->assertOk();
    $this->get(route('login'))->assertSee('Sign up');
});

it('creates users from the command line', function () {
    $this->artisan('newsstand:user', ['email' => 'Colleague@Example.com', '--name' => 'Colleague'])
        ->expectsOutputToContain('Created Colleague <colleague@example.com>')
        ->expectsOutputToContain('Password:')
        ->assertSuccessful();

    $user = User::where('email', 'colleague@example.com')->sole();
    expect($user->email_verified_at)->not->toBeNull();

    $this->artisan('newsstand:user', ['email' => 'colleague@example.com'])->assertFailed();
    $this->artisan('newsstand:user', ['email' => 'not-an-email'])->assertFailed();
});

// --- Security headers ---

it('sends security headers', function () {
    $this->get('/')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://localhost/')->assertHeader('Strict-Transport-Security');
});

// --- Daily digest ---

function digestUser(): array
{
    $user = User::factory()->create();
    $feed = Feed::factory()->create(['title' => 'BleepingComputer']);
    $user->subscriptions()->create(['feed_id' => $feed->id]);
    $story = $feed->entries()->create([
        'guid' => 'a', 'guid_hash' => sha1('a'), 'title' => 'Citrix Bleed exploited again',
        'url' => 'https://example.com/citrix', 'published_at' => now()->subHours(2),
    ]);
    $story->cves()->attach(Cve::create(['cve_id' => 'CVE-2023-4966', 'in_kev' => true, 'cvss_score' => 9.4, 'kev_name' => 'Citrix NetScaler Information Disclosure']));

    return [$user, $story];
}

it('sends a digest at the chosen hour, once a day', function () {
    Mail::fake();
    [$user] = digestUser();
    $user->forceFill(['digest_enabled' => true, 'digest_hour' => now()->hour])->save();

    $this->artisan('digest:send')->expectsOutputToContain('Queued 1 digest(s)')->assertSuccessful();
    Mail::assertQueued(DailyDigest::class, fn ($mail) => $mail->hasTo($user->email)
        && str_contains($mail->envelope()->subject, '1 actively exploited vulnerability'));

    // Not twice on the same day.
    $this->artisan('digest:send')->expectsOutputToContain('Queued 0 digest(s) for 0 user(s)');
    Mail::assertQueued(DailyDigest::class, 1);
});

it('skips users who did not opt in, other hours and empty days', function () {
    Mail::fake();
    [$user] = digestUser();
    $user->forceFill(['digest_enabled' => true, 'digest_hour' => (now()->hour + 3) % 24])->save();
    $optedOut = User::factory()->create(['digest_enabled' => false]);
    $nothingNew = User::factory()->create();
    $nothingNew->forceFill(['digest_enabled' => true, 'digest_hour' => now()->hour])->save();

    $this->artisan('digest:send')->assertSuccessful();

    Mail::assertNothingQueued();
});

it('renders the digest email', function () {
    [$user] = digestUser();
    $digest = app(DigestBuilder::class)->for($user);

    $html = (new DailyDigest($user, $digest))->render();

    expect($html)->toContain('Actively exploited')
        ->toContain('CVE-2023-4966')
        ->toContain('Citrix Bleed exploited again')
        ->toContain('Open Newsstand');
});

it('manages digest settings', function () {
    Mail::fake();
    [$user] = digestUser();
    $this->actingAs($user);

    Livewire::test('pages::settings.digest')
        ->set('digestEnabled', true)
        ->set('digestHour', 6)
        ->call('save')
        ->assertHasNoErrors()
        ->call('sendTest');

    expect($user->fresh()->only('digest_enabled', 'digest_hour'))->toBe(['digest_enabled' => true, 'digest_hour' => 6]);
    Mail::assertSent(DailyDigest::class);
});
