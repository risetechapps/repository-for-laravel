<?php

use RiseTechApps\Repository\Tests\Fixtures\ProductEloquentRepository;

beforeEach(function () {
    $this->repo = new ProductEloquentRepository();
});

it('maps friendly warming method names to repository constants', function () {
    config()->set('repository.cache.warming_methods', ['get', 'first', 'dataTable', 'findById']);

    $method = new ReflectionMethod($this->repo, 'resolveWarmingMethods');
    $method->setAccessible(true);

    // findById é descartado (depende de um id indisponível no contexto).
    expect($method->invoke($this->repo))->toBe(['ALL', 'FIRST', 'DATATABLE']);
});

it('ignores unknown warming method names', function () {
    config()->set('repository.cache.warming_methods', ['get', 'inexistente']);

    $method = new ReflectionMethod($this->repo, 'resolveWarmingMethods');
    $method->setAccessible(true);

    expect($method->invoke($this->repo))->toBe(['ALL']);
});
