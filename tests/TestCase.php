<?php

namespace RiseTechApps\Repository\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use RiseTechApps\Repository\RepositoryServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * Driver do banco usado na suíte. Default sqlite (:memory:); a CI roda um
     * segundo job com DB_CONNECTION=pgsql para exercitar de verdade os recursos
     * PG-only (views materializadas, fuzzySearch, searchFullText, JSONB), que em
     * sqlite só têm cobertura de validação.
     */
    public static function driver(): string
    {
        return env('DB_CONNECTION') === 'pgsql' ? 'pgsql' : 'sqlite';
    }

    public static function onPostgres(): bool
    {
        return static::driver() === 'pgsql';
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Catálogo administrativo `materialized_views`: o provider registra a
        // migration, mas a suíte não roda um `migrate` completo.
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            RepositoryServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', $this->testingConnection());

        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
    }

    /**
     * @return array<string, mixed>
     */
    protected function testingConnection(): array
    {
        if (static::onPostgres()) {
            return [
                'driver' => 'pgsql',
                'host' => env('DB_HOST', '127.0.0.1'),
                'port' => (int) env('DB_PORT', 5432),
                'database' => env('DB_DATABASE', 'testing'),
                'username' => env('DB_USERNAME', 'postgres'),
                'password' => env('DB_PASSWORD', 'postgres'),
                'charset' => 'utf8',
                'prefix' => '',
                'search_path' => 'public',
                'sslmode' => 'prefer',
            ];
        }

        return [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ];
    }
}
