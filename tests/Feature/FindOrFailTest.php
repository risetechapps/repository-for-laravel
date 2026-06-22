<?php

use RiseTechApps\Repository\Exception\EntityNotFoundException;
use RiseTechApps\Repository\Tests\Fixtures\ProductEloquentRepository;

beforeEach(function () {
    $this->repo = new ProductEloquentRepository();
});

it('throws EntityNotFoundException when the record does not exist', function () {
    $this->repo->findOrFail(999);
})->throws(EntityNotFoundException::class);

it('returns the model when the record exists', function () {
    $p = $this->repo->store(['name' => 'A']);

    expect($this->repo->findOrFail($p->id)->id)->toBe($p->id);
});
