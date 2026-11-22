<?php

use App\Jobs\FetchFeed;
use App\Models\Feed;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

// --- Who is admin ---

it('makes the first registered account an admin, and later ones not', function () {
    config(['newsstand.registration' => 'open']);

    foreach (['first@example.com', 'second@example.com'] as $email) {
        $this->post(route('register.store'), ['name' => 'X', 'email' => $email, 'password' => 'password', 'password_confirmation' => 'password']);
        auth()->logout();
    }

    expect(User::where('email', 'first@example.com')->value('is_admin'))->toBeTrue()
        ->and(User::where('email', 'second@example.com')->value('is_admin'))->toBeFalse();
});

it('creates admins and changes admin rights from the command line', function () {
    $this->artisan('newsstand:user', ['email' => 'boss@example.com', '--admin' => true])
        ->expectsOutputToContain('(admin)')->assertSuccessful();
    $other = User::factory()->create(['email' => 'other@example.com']);

    $this->artisan('newsstand:admin', ['email' => 'other@example.com'])->assertSuccessful();
    expect($other->fresh()->is_admin)->toBeTrue();

    $this->artisan('newsstand:admin', ['email' => 'other@example.com', '--revoke' => true])->assertSuccessful();
    expect($other->fresh()->is_admin)->toBeFalse();

    // The last admin can't be revoked.
    $this->artisan('newsstand:admin', ['email' => 'boss@example.com', '--revoke' => true])->assertFailed();
    expect(User::where('email', 'boss@example.com')->value('is_admin'))->toBeTrue();
});

// --- Access ---

it('keeps non-admins out of the admin panel and Horizon', function () {
    $user = User::factory()->create();
    $admin = User::factory()->admin()->create();

    foreach (['admin.users', 'admin.settings', 'admin.health'] as $route) {
        $this->actingAs($user)->get(route($route))->assertForbidden();
        $this->actingAs($admin)->get(route($route))->assertOk();
    }

    expect(Gate::forUser($user)->allows('viewHorizon'))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('viewHorizon'))->toBeTrue();

    $this->actingAs($user)->get(route('reader'))->assertDontSee('Administration');
    $this->actingAs($admin)->get(route('reader'))->assertSee('Administration');
});

// --- Users page ---

it('creates users and shows the generated password once', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('pages::admin.users')
        ->set('newEmail', 'Newbie@Example.com')
        ->set('newName', 'Newbie')
        ->call('create')
        ->assertHasNoErrors()
        ->assertSee('Temporary password')
        ->set('newEmail', 'newbie@example.com')
        ->call('create')
        ->assertHasErrors('newEmail');

    $user = User::where('email', 'newbie@example.com')->sole();
    expect($user->email_verified_at)->not->toBeNull()->and($user->is_admin)->toBeFalse();
});

it('promotes, demotes and protects the last admin', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();

    Livewire::actingAs($admin)->test('pages::admin.users')->call('toggleAdmin', $user->id);
    expect($user->fresh()->is_admin)->toBeTrue();

    Livewire::actingAs($admin)->test('pages::admin.users')->call('toggleAdmin', $user->id)->call('toggleAdmin', $admin->id);
    expect($user->fresh()->is_admin)->toBeFalse()
        ->and($admin->fresh()->is_admin)->toBeTrue(); // last admin stays
});

it('disables accounts: no login, and signed-in sessions end', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create(['email' => 'victim@example.com']);

    Livewire::actingAs($admin)->test('pages::admin.users')->call('toggleDisabled', $user->id);
    expect($user->fresh()->isDisabled())->toBeTrue();

    // Already signed in: thrown out on the next request.
    $this->actingAs($user->fresh())->get(route('reader'))->assertRedirect(route('login'));
    $this->assertGuest();

    // Can't sign in again.
    $this->post(route('login.store'), ['email' => 'victim@example.com', 'password' => 'password'])
        ->assertSessionHasErrors('email');
    $this->assertGuest();

    // Re-enabled: can sign in.
    Livewire::actingAs($admin)->test('pages::admin.users')->call('toggleDisabled', $user->id);
    auth()->logout();
    $this->post(route('login.store'), ['email' => 'VICTIM@example.com', 'password' => 'password']);
    $this->assertAuthenticatedAs($user->fresh());
});

it('won\'t let admins disable or delete themselves', function () {
    $admin = User::factory()->admin()->create();

    Livewire::actingAs($admin)->test('pages::admin.users')->call('toggleDisabled', $admin->id)->call('delete', $admin->id);

    expect($admin->fresh())->not->toBeNull()->and($admin->fresh()->isDisabled())->toBeFalse();
});

it('deletes users with their data', function () {
    $admin = User::factory()->admin()->create();
    $user = User::factory()->create();
    $user->subscriptions()->create(['feed_id' => Feed::factory()->create()->id]);

    Livewire::actingAs($admin)->test('pages::admin.users')->call('delete', $user->id);

    expect(User::find($user->id))->toBeNull()
        ->and(DB::table('subscriptions')->where('user_id', $user->id)->count())->toBe(0);
});

// --- Settings ---

it('changes registration from the panel, overriding the server default', function () {
    config(['newsstand.registration' => 'open']);
    $admin = User::factory()->admin()->create();
    $this->get(route('register'))->assertOk();

    Livewire::actingAs($admin)->test('pages::admin.settings')->set('registration', 'disabled')->call('save')->assertHasNoErrors();
    auth()->logout();
    $this->get(route('register'))->assertNotFound();

    Livewire::actingAs($admin)->test('pages::admin.settings')->call('useEnvironmentDefault')->assertSet('registration', 'open');
    expect(Setting::get('registration'))->toBeNull();
    auth()->logout();
    $this->get(route('register'))->assertOk();

    Livewire::actingAs($admin)->test('pages::admin.settings')->set('registration', 'nonsense')->call('save')->assertHasErrors('registration');
});

// --- Health ---

it('reports health and retries failed jobs and broken feeds', function () {
    Queue::fake();
    $admin = User::factory()->admin()->create();
    $feed = Feed::factory()->create(['title' => 'Broken feed']);
    $feed->forceFill(['error_count' => 3, 'last_error' => 'HTTP 500'])->save();
    $admin->subscriptions()->create(['feed_id' => $feed->id]);
    DB::table('failed_jobs')->insert([
        'uuid' => 'abc-123', 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['displayName' => 'App\\Jobs\\EnrichCve']), 'exception' => "RuntimeException: boom\n#0 trace", 'failed_at' => now(),
    ]);
    Setting::set('scheduler.last_run_at', now()->subMinute()->toIso8601String());

    Livewire::actingAs($admin)->test('pages::admin.health')
        ->assertSee('Broken feed')
        ->assertSee('HTTP 500')
        ->assertSee('EnrichCve')
        ->assertSee('RuntimeException: boom')
        ->assertSee('last run')
        ->call('retryFeed', $feed->id)
        ->call('forgetJob', 'abc-123')
        ->assertDontSee('RuntimeException: boom');

    Queue::assertPushed(FetchFeed::class);
});

it('records when the KEV catalog was last synced', function () {
    Http::fake(['*' => Http::response(file_get_contents(base_path('tests/Fixtures/security/kev.json')))]);
    Queue::fake();

    $this->artisan('security:sync-kev')->assertSuccessful();
    expect(Setting::get('kev.last_synced_at'))->not->toBeNull();
});
