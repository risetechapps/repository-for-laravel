<?php

use RiseTechApps\Repository\Tests\Fixtures\Product;
use RiseTechApps\Repository\Tests\Fixtures\ProductEloquentRepository;

beforeEach(function () {
    $this->repo = new ProductEloquentRepository();
});

it('caches an empty result by default', function () {
    expect($this->repo->get())->toBeEmpty();

    // Insere por fora do repositório (não invalida o cache do repo).
    Product::create(['name' => 'X']);

    // Como o vazio foi cacheado, ainda retorna vazio.
    expect($this->repo->get())->toBeEmpty();
});

it('does not cache when cacheIf rejects the result', function () {
    expect($this->repo->cacheIf(fn($r) => $r->isNotEmpty())->get())->toBeEmpty();

    Product::create(['name' => 'X']);

    // Como o vazio NÃO foi cacheado, agora enxerga o novo registro.
    expect($this->repo->cacheIf(fn($r) => $r->isNotEmpty())->get())->toHaveCount(1);
});
