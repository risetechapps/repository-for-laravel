<?php

namespace RiseTechApps\Repository\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use RiseTechApps\Repository\RepositoryServiceProvider;

abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

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
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
    }
}
