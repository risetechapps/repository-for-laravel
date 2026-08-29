<?php

use Illuminate\Support\Facades\Cache;
use RiseTechApps\Repository\Repository;
use RiseTechApps\Repository\Tests\Fixtures\Product;
use RiseTechApps\Repository\Tests\Fixtures\ProductEloquentRepository;

beforeEach(function () {
    config()->set('repository.cache.warming_enabled', false);
    Cache::flush();
});

it('caches paginate through the builder path and invalidates it on clearCacheForEntity', function () {
    Product::create(['name' => 'A']);

    $repo = new ProductEloquentRepository();

    // currentBuilder definido => paginate() cai em paginateWithView() (com cache)
    $first = $repo->where('name', 'A')->paginate(10);
    expect($first['recordsTotal'])->toBe(1);

    Product::create(['name' => 'A']);

    // Ainda cacheado: o novo registro nao aparece
    $cached = (new ProductEloquentRepository())->where('name', 'A')->paginate(10);
    expect($cached['recordsTotal'])->toBe(1);

    (new ProductEloquentRepository())->clearCacheForEntity();

    $after = (new ProductEloquentRepository())->where('name', 'A')->paginate(10);
    expect($after['recordsTotal'])->toBe(2);
});

it('invalidates the paginate cache when a write goes through the repository', function () {
    Product::create(['name' => 'A']);

    $repo = new ProductEloquentRepository();
    expect($repo->where('name', 'A')->paginate(10)['recordsTotal'])->toBe(1);

    (new ProductEloquentRepository())->store(['name' => 'A']);

    expect((new ProductEloquentRepository())->where('name', 'A')->paginate(10)['recordsTotal'])->toBe(2);
});
