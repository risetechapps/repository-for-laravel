<?php

use RiseTechApps\Repository\Tests\Fixtures\ProductEloquentRepository;

beforeEach(function () {
    $this->repo = new ProductEloquentRepository();
});

it('rolls back the transaction on exception', function () {
    try {
        $this->repo->transaction(function () {
            $this->repo->store(['name' => 'A']);
            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
        // esperado
    }

    expect($this->repo->withoutCache()->count())->toBe(0);
});

it('commits and returns the callback value', function () {
    $result = $this->repo->transaction(function () {
        return $this->repo->store(['name' => 'A']);
    });

    expect($result->name)->toBe('A')
        ->and($this->repo->withoutCache()->count())->toBe(1);
});
