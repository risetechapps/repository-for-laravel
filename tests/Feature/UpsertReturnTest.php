<?php

use Illuminate\Support\Facades\Event;
use RiseTechApps\Repository\Events\RepositoryUpdating;
use RiseTechApps\Repository\Tests\Fixtures\Product;
use RiseTechApps\Repository\Tests\Fixtures\ProductEloquentRepository;

/**
 * updateOrCreate()/createOrUpdate() devolviam o model no caminho "criar" e o
 * bool de update() no caminho "atualizar" — quebrava a partir da 2ª chamada.
 */
beforeEach(function () {
    $this->repo = new ProductEloquentRepository();
});

it('returns the model on both paths of updateOrCreate', function () {
    $criado = $this->repo->updateOrCreate(['name' => 'Alfa'], ['stock' => 1]);

    expect($criado)->toBeInstanceOf(Product::class)
        ->and($criado->stock)->toBe(1);

    $atualizado = $this->repo->updateOrCreate(['name' => 'Alfa'], ['stock' => 2]);

    // Antes: bool(true) — e `->stock` dava "property on bool".
    expect($atualizado)->toBeInstanceOf(Product::class)
        ->and($atualizado->stock)->toBe(2)
        ->and($atualizado->getKey())->toBe($criado->getKey())
        ->and(Product::count())->toBe(1);
});

it('returns the model on both paths of createOrUpdate', function () {
    $criado = $this->repo->createOrUpdate(999999, ['name' => 'Beta', 'stock' => 1]);

    expect($criado)->toBeInstanceOf(Product::class);

    $atualizado = $this->repo->createOrUpdate($criado->getKey(), ['stock' => 7]);

    expect($atualizado)->toBeInstanceOf(Product::class)
        ->and($atualizado->stock)->toBe(7)
        ->and($atualizado->getKey())->toBe($criado->getKey());
});

it('returns the post-write state, not the record used to pick the path', function () {
    $this->repo->updateOrCreate(['name' => 'Gama'], ['stock' => 1]);

    // Aquece o cache do first() usado internamente para escolher o caminho.
    $this->repo->firstOrCreate(['name' => 'Gama']);

    $atualizado = $this->repo->updateOrCreate(['name' => 'Gama'], ['stock' => 42]);

    expect($atualizado->stock)->toBe(42);
});

it('keeps returning the model for firstOrCreate on both paths', function () {
    $a = $this->repo->firstOrCreate(['name' => 'Delta'], ['stock' => 1]);
    $b = $this->repo->firstOrCreate(['name' => 'Delta'], ['stock' => 9]);

    expect($a)->toBeInstanceOf(Product::class)
        ->and($b)->toBeInstanceOf(Product::class)
        ->and($b->getKey())->toBe($a->getKey())
        ->and($b->stock)->toBe(1); // firstOrCreate não atualiza
});

it('returns null instead of false when a listener vetoes the update', function () {
    $criado = $this->repo->updateOrCreate(['name' => 'Epsilon'], ['stock' => 1]);

    Event::listen(RepositoryUpdating::class, function ($event) {
        $event->shouldUpdate = false;
    });

    $resultado = $this->repo->updateOrCreate(['name' => 'Epsilon'], ['stock' => 99]);

    expect($resultado)->toBeNull()
        ->and(Product::find($criado->getKey())->stock)->toBe(1);
});
