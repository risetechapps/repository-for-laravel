<?php

namespace RiseTechApps\Repository;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RiseTechApps\Repository\Commands\GenerateRepositoryCommand;
use RiseTechApps\Repository\Commands\RepositoryClearCacheCommand;
use RiseTechApps\Repository\Commands\RepositoryRefreshMaterializedViewsCommand;
use RiseTechApps\Repository\Commands\RepositoryRestartMaterializedViewsCommand;
use RiseTechApps\Repository\Http\Middleware\CacheApiResponse;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     */
    public function boot(): void
    {

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/config.php' => config_path('repository.php'),
            ], 'config');
        }

        $this->commands([
            GenerateRepositoryCommand::class,
            RepositoryRefreshMaterializedViewsCommand::class,
            RepositoryClearCacheCommand::class,
            RepositoryRestartMaterializedViewsCommand::class,
        ]);

        if (!Str::hasMacro('qualifyTagCacheResponse')) {
            Str::macro('qualifyTagCacheResponse', function ($value) {

                return str_replace('\\', '.', $value);
            });
        }

        app('router')->aliasMiddleware('cacheResponse', CacheApiResponse::class);
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
