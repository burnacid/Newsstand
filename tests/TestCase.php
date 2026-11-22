<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Tests use RefreshDatabase, which wipes the database. Refuse to run unless
     * we're clearly on a test database. This guards against a cached config
     * (php artisan optimize / config:cache), which makes Laravel ignore the
     * settings in phpunit.xml and would point the tests at your real data.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = (string) $app['config']->get("database.connections.{$connection}.database");

        $safe = $app->environment('testing')
            && ($database === ':memory:' || str_contains(strtolower($database), 'test'));

        if (! $safe) {
            throw new RuntimeException(
                "Refusing to run tests against database [{$connection}: {$database}] in environment [{$app->environment()}]. ".
                'If you cached the config, run `php artisan optimize:clear` first.'
            );
        }

        return $app;
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
