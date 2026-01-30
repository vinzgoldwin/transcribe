<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        $this->ensureSafeTestEnvironment();

        parent::setUp();
    }

    private function ensureSafeTestEnvironment(): void
    {
        $appEnv = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? ($_SERVER['APP_ENV'] ?? null));

        if ($appEnv !== 'testing') {
            throw new \RuntimeException('Refusing to run tests unless APP_ENV=testing.');
        }

        $configCachePath = dirname(__DIR__).'/bootstrap/cache/config.php';

        if (is_file($configCachePath)) {
            throw new \RuntimeException('Refusing to run tests while config cache exists. Run "php artisan config:clear" to avoid cached local database settings.');
        }

        $dbConnection = getenv('DB_CONNECTION') ?: ($_ENV['DB_CONNECTION'] ?? ($_SERVER['DB_CONNECTION'] ?? null));
        $dbDatabase = getenv('DB_DATABASE') ?: ($_ENV['DB_DATABASE'] ?? ($_SERVER['DB_DATABASE'] ?? null));

        if ($dbConnection !== 'sqlite' || $dbDatabase !== ':memory:') {
            throw new \RuntimeException('Refusing to run tests unless DB_CONNECTION=sqlite and DB_DATABASE=:memory:.');
        }
    }
}
