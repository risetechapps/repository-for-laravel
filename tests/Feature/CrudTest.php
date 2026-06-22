<?php

use RiseTechApps\Repository\Tests\Fixtures\Product;
use RiseTechApps\Repository\Tests\Fixtures\ProductEloquentRepository;

beforeEach(function () {
    $this->repo = new ProductEloquentRepository();
});

it('stores and finds a record', function () {
    $product = $this->repo->store(['name' => 'Widget', 'stock' => 5]);

    expect($product)->not->toBeNull()
        ->and($this->repo->findById($product->id)->name)->toBe('Widget');
});

it('updates a record', function () {
    $p = $this->repo->store(['name' => 'A']);

    $this->repo->update($p->id, ['name' => 'B']);

    expect($this->repo->withoutCache()->findById($p->id)->name)->toBe('B');
});

it('soft deletes a record and finds it via onlyTrashed', function () {
    $p = $this->repo->store(['name' => 'A']);

    $this->repo->find($p->id)->delete();

    expect($this->repo->withoutCache()->findById($p->id))->toBeNull()
        ->and($this->repo->onlyTrashed()->count())->toBe(1);
});

it('registerViews defaults to an empty array', function () {
    expect($this->repo->registerViews())->toBe([]);
});
