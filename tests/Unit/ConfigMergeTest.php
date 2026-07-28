<?php

use RiseTechApps\Repository\Repository;

it('merges the package config so repository.* exists without publishing', function () {
    // Sem mergeConfigFrom no ServiceProvider todo o namespace `repository` fica null
    // quando a app não publica config/repository.php — e REPOSITORY_CACHE_STORE
    // nunca chega a ser avaliado.
    expect(config('repository'))->toBeArray()
        ->and(config('repository.cache'))->toBeArray()
        ->and(config()->has('repository.cache.store'))->toBeTrue()
        ->and(config('repository.cache.warming_enabled'))->toBeTrue()
        ->and(config('repository.cache.warming_methods'))->toBe(['get', 'first'])
        ->and(config('repository.repositories'))->toBe([]);
});

it('honours repository.cache.store over the application default', function () {
    expect(Repository::cacheStoreName())->toBeNull();

    config()->set('repository.cache.store', 'array');

    expect(Repository::cacheStoreName())->toBe('array');
});

it('treats the array store as taggable', function () {
    // ArrayStore estende TaggableStore: incluí-lo em unsupported_tag_drivers
    // desligaria a invalidação por tag em teste.
    expect(config('repository.cache.unsupported_tag_drivers'))->not->toContain('array')
        ->and(Repository::$driverNotSupported)->not->toContain('array')
        ->and(config('cache.default'))->toBe('array')
        ->and(Repository::storeSupportsTags())->toBeTrue();
});

it('reports file and database stores as not taggable', function () {
    config()->set('repository.cache.store', 'file');
    expect(Repository::storeSupportsTags())->toBeFalse();

    config()->set('repository.cache.store', 'database');
    expect(Repository::storeSupportsTags())->toBeFalse();
});
