<?php

use App\Models\Entry;
use App\Models\User;
use Database\Seeders\DemoSeeder;

it('seeds sample data for demos and screenshots', function () {
    $this->seed(DemoSeeder::class);

    $user = User::where('email', DemoSeeder::EMAIL)->sole();
    expect(Entry::count())->toBeGreaterThan(20)
        ->and($user->subscriptions()->count())->toBe(6)
        ->and($user->dashboards()->count())->toBe(1)
        ->and(DB::table('cve_entry')->count())->toBeGreaterThan(0)
        ->and(DB::table('rule_entry')->count())->toBeGreaterThan(2);
});

it('refuses to run on a database with real users', function () {
    User::factory()->create(['email' => 'someone@example.com']);

    $this->seed(DemoSeeder::class);
})->throws(RuntimeException::class);
