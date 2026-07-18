<?php

namespace RiseTechApps\Repository;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use RiseTechApps\Repository\Commands\GenerateRepositoryCommand;
use RiseTechApps\Repository\Commands\RepositoryClearCacheCommand;
use RiseTechApps\Repository\Commands\RepositoryRefreshMaterializedViewsCommand;
use RiseTechApps\Repository\Commands\RepositoryRestartMaterializedViewsCommand;
use RiseTechApps\Repository\Commands\RepositorySearchIndexesCommand;
use RiseTechApps\Repository\Commands\RepositoryWarmCacheCommand;
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
            RepositoryWarmCacheCommand::class,
            RepositorySearchIndexesCommand::class,
        ]);

        if (!Str::hasMacro('qualifyTagCacheResponse')) {
            Str::macro('qualifyTagCacheResponse', fn($value) => str_replace('\\', '.', $value));
        }

        app('router')->aliasMiddleware('cacheResponse', CacheApiResponse::class);

        $this->warnIfCacheStoreDoesNotSupportTags();
    }

    /**
     * Register the application services.
     */
    #[\Override]
    public function register(): void
    {
        $this->app->singleton('repository', fn() => new Repository());

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

    /**
     * Alerta quando o store de cache do repositório não suporta tags: nesse
     * caso a invalidação por tag (core e cacheResponse) vira no-op e o cache só
     * expira por TTL (Furo 2). Aponte repository.cache.store para um store redis.
     */
    private function warnIfCacheStoreDoesNotSupportTags(): void
    {
        if (Repository::storeSupportsTags()) {
            return;
        }

        $store = Repository::cacheStoreName() ?? config('cache.default');

        logger()->warning(
            "[repository-for-laravel] O store de cache '{$store}' não suporta tags. " .
            'A invalidação de cache do repositório e do middleware cacheResponse ' .
            'ficará limitada ao TTL (nenhum flush no write). Defina repository.cache.store ' .
            'para um store redis/memcached.'
        );
    }
}
