<?php

namespace App\Providers;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Gate::define('admin', fn (User $user) => $user->isAdmin() && ! $user->isDisabled());

        // NVD allows ~5 requests / 30s without an API key and ~50 / 30s with one.
        // Spread requests out instead of allowing bursts: one every 7s without a key, one per second with.
        RateLimiter::for('nvd', fn () => config('newsstand.security.nvd_api_key') ? Limit::perSecond(1) : Limit::perSecond(1, 7));

        // Article scans: at most one request every 3 seconds per site.
        RateLimiter::for('article-scan', fn (object $job) => Limit::perSecond(1, 3)->by(
            'article-scan:'.(parse_url((string) ($job->entry->url ?? ''), PHP_URL_HOST) ?: 'unknown')
        ));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
