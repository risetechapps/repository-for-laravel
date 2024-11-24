<?php

namespace RiseTechApps\Repository;

use Illuminate\Support\ServiceProvider;
use RiseTechApps\Repository\Commands\GenerateRepositoryCommand;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/config.php' => config_path('repository.php'),
            ], 'config');
        }

        $this->commands([
            GenerateRepositoryCommand::class
        ]);
    }

    /**
     * Register the application services.
     */
    public function register(): void
    {
        $this->app->singleton('repository', function () {
            return new Repository();
        });

        $this->app->singleton(Repository::class);

        $this->registerRepositories();
    }

    private function registerRepositories(): void
    {
        $repositories = config('repository.repositories', []);

        foreach ($repositories as $repository => $value) {
            $this->app->bind($repository, $value);
        }
    }
}
