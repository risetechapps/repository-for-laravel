<?php

use RiseTechApps\Repository\Core\BaseRepository;
use RiseTechApps\Repository\Tests\Fixtures\Product;

/**
 * Repositório com um default scope sem parâmetros: 'active' é aplicado
 * automaticamente em toda query.
 */
function makeActiveScopedRepository(): BaseRepository
{
    return new class extends BaseRepository {
        protected array $defaultScopes = ['active'];

        public function entity(): string
        {
            return Product::class;
        }

        public function entityOn(): Product
        {
            return new Product();
        }

        protected function scopeActive($query)
        {
            return $query->where('status', 'active');
        }
    };
}

beforeEach(function () {
    Product::create(['name' => 'Ativo', 'status' => 'active', 'stock' => 20]);
    Product::create(['name' => 'Inativo', 'status' => 'inactive', 'stock' => 5]);
});

it('applies a default scope automatically on get() without calling scope()', function () {
    $all = makeActiveScopedRepository()->withoutCache()->get();

    expect($all)->toHaveCount(1)
        ->and($all->first()->name)->toBe('Ativo');
});

it('applies the default scope consistently in first()', function () {
    $first = makeActiveScopedRepository()->withoutCache()->first();

    expect($first)->not->toBeNull()
        ->and($first->name)->toBe('Ativo');
});

it('composes the default scope with an explicit scope() — only scope(B) needed', function () {
    $repo = new class extends BaseRepository {
        protected array $defaultScopes = ['active'];

        public function entity(): string
        {
            return Product::class;
        }

        public function entityOn(): Product
        {
            return new Product();
        }

        protected function scopeActive($q)
        {
            return $q->where('status', 'active');
        }

        protected function scopeStockAtLeast($q, int $min)
        {
            return $q->where('stock', '>=', $min);
        }
    };

    // 'active' (default) + stock>=10 → só o Ativo (stock 20); o Inativo nem entra.
    $result = $repo->withoutCache()->scope('stockAtLeast', 10)->get();

    expect($result)->toHaveCount(1)
        ->and($result->first()->name)->toBe('Ativo');
});

it('supports default scopes declared with parameters', function () {
    $repo = new class extends BaseRepository {
        protected array $defaultScopes = ['stockAtLeast' => [10]];

        public function entity(): string
        {
            return Product::class;
        }

        public function entityOn(): Product
        {
            return new Product();
        }

        protected function scopeStockAtLeast($q, int $min)
        {
            return $q->where('stock', '>=', $min);
        }
    };

    $result = $repo->withoutCache()->get();

    // stock>=10 aplicado automaticamente → Ativo (20) entra, Inativo (5) não.
    expect($result)->toHaveCount(1)
        ->and($result->first()->name)->toBe('Ativo');
});

it('skips a default scope for one query via withoutScope()', function () {
    $repo = makeActiveScopedRepository();

    expect($repo->withoutCache()->withoutScope('active')->get())->toHaveCount(2);
});

it('resets withoutScope() after the operation (does not leak to the next query)', function () {
    $repo = makeActiveScopedRepository();

    $repo->withoutCache()->withoutScope('active')->get(); // ignora o default nesta
    $next = $repo->withoutCache()->get();                 // default deve voltar a valer

    expect($next)->toHaveCount(1)
        ->and($next->first()->name)->toBe('Ativo');
});

it('tolerates a default scope that does not return the builder', function () {
    $repo = new class extends BaseRepository {
        protected array $defaultScopes = ['active'];

        public function entity(): string
        {
            return Product::class;
        }

        public function entityOn(): Product
        {
            return new Product();
        }

        // Sem return de propósito (igual aos local scopes do Eloquent).
        protected function scopeActive($query)
        {
            $query->where('status', 'active');
        }
    };

    expect($repo->withoutCache()->get())->toHaveCount(1);
});

it('throws when a default scope method does not exist', function () {
    $repo = new class extends BaseRepository {
        protected array $defaultScopes = ['inexistente'];

        public function entity(): string
        {
            return Product::class;
        }

        public function entityOn(): Product
        {
            return new Product();
        }
    };

    $repo->withoutCache()->get();
})->throws(BadMethodCallException::class);
