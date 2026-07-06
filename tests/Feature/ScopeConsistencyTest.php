<?php

use RiseTechApps\Repository\Tests\Fixtures\ProductEloquentRepository;

beforeEach(function () {
    $this->repo = new ProductEloquentRepository();

    $this->active  = $this->repo->store(['name' => 'Active']);
    $this->trashed = $this->repo->store(['name' => 'Trashed']);
    $this->repo->find($this->trashed->id)->delete();
});

it('applies onlyTrashed consistently in get() and first() regardless of chaining order', function () {
    // onlyTrashed() é chamado DEPOIS de select(): o escopo precisa valer no terminal,
    // não no momento em que o currentBuilder foi montado.
    $viaGet = $this->repo->withoutCache()->select(['name'])->onlyTrashed()->get();
    $viaFirst = $this->repo->withoutCache()->select(['name'])->onlyTrashed()->first();

    expect($viaGet)->toHaveCount(1)
        ->and($viaGet->first()->name)->toBe('Trashed')
        ->and($viaFirst)->not->toBeNull()
        ->and($viaFirst->name)->toBe('Trashed');
});

it('keeps default scope (active only) consistent in get() and first() after select()', function () {
    $viaGet = $this->repo->withoutCache()->select(['name'])->get();
    $viaFirst = $this->repo->withoutCache()->select(['name'])->first();

    expect($viaGet)->toHaveCount(1)
        ->and($viaGet->first()->name)->toBe('Active')
        ->and($viaFirst)->not->toBeNull()
        ->and($viaFirst->name)->toBe('Active');
});

it('applies onlyTrashed after where() in both get() and first()', function () {
    $viaGet = $this->repo->withoutCache()->where('name', 'Trashed')->onlyTrashed()->get();
    $viaFirst = $this->repo->withoutCache()->where('name', 'Trashed')->onlyTrashed()->first();

    expect($viaGet)->toHaveCount(1)
        ->and($viaFirst)->not->toBeNull()
        ->and($viaFirst->name)->toBe('Trashed');
});
