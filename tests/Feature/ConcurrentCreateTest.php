<?php

use Illuminate\Support\Facades\Event;
use RiseTechApps\Repository\Events\RepositoryCreating;
use RiseTechApps\Repository\Repository;
use RiseTechApps\Repository\Tests\Fixtures\Product;
use RiseTechApps\Repository\Tests\Fixtures\ProductEloquentRepository;

/**
 * firstOrCreate/updateOrCreate decidiam criar com base numa leitura cacheada
 * (rememberCache guarda resultado negativo, TTL default 24h) e não tratavam a
 * criação concorrente — o insert perdedor estourava QueryException.
 */
beforeEach(function () {
    $this->repo = new ProductEloquentRepository();
});

/**
 * Simula "outro processo criou primeiro": grava o registro direto pelo Eloquent
 * no meio do store(), depois que o nosso select já respondeu "não existe".
 */
function criaConcorrenteDurante(array $data): void
{
    $jaCriou = false;

    Event::listen(RepositoryCreating::class, function () use ($data, &$jaCriou) {
        if ($jaCriou) {
            return;
        }

        $jaCriou = true;
        Product::create($data);
    });
}

it('returns the record created by the other process in firstOrCreate', function () {
    criaConcorrenteDurante(['name' => 'Do concorrente', 'sku' => 'SKU-1', 'stock' => 5]);

    $result = $this->repo->firstOrCreate(['sku' => 'SKU-1'], ['name' => 'Meu', 'stock' => 1]);

    // Antes: UniqueConstraintViolationException sem tratamento.
    expect($result)->toBeInstanceOf(Product::class)
        ->and($result->name)->toBe('Do concorrente')
        ->and($result->stock)->toBe(5)
        ->and(Product::where('sku', 'SKU-1')->count())->toBe(1);
});

it('applies the values on the record created by the other process in updateOrCreate', function () {
    criaConcorrenteDurante(['name' => 'Do concorrente', 'sku' => 'SKU-2', 'stock' => 5]);

    $result = $this->repo->updateOrCreate(['sku' => 'SKU-2'], ['name' => 'Meu', 'stock' => 99]);

    // Recupera da colisão e ainda faz o que o caminho "atualizar" faria.
    expect($result)->toBeInstanceOf(Product::class)
        ->and($result->stock)->toBe(99)
        ->and($result->name)->toBe('Meu')
        ->and(Product::where('sku', 'SKU-2')->count())->toBe(1);
});

it('rethrows when the unique violation is on another constraint', function () {
    // Colide numa constraint que não é a de $attributes: não há registro para
    // recuperar, então a exception tem que continuar subindo.
    Product::create(['name' => 'Existente', 'sku' => 'SKU-OCUPADO']);

    $this->repo->firstOrCreate(['name' => 'Outro'], ['sku' => 'SKU-OCUPADO']);
})->throws(\Illuminate\Database\UniqueConstraintViolationException::class);

it('does not decide from a stale cached model in updateOrCreate', function () {
    $attributes = ['sku' => 'SKU-3'];

    $original = Product::create(['name' => 'Antigo', 'sku' => 'SKU-3', 'stock' => 1]);

    // Envenena o cache na mesma chave que a implementação antiga consultava.
    // Valor não-nulo é servido normalmente pelo cache (ao contrário de null,
    // que Illuminate\Cache\Repository::get() sempre trata como miss).
    $this->repo->rememberCache(fn() => $original, Repository::$methodFirst, [$attributes]);

    // Some do banco sem passar pelo repositório: o cache não é invalidado.
    Product::where('sku', 'SKU-3')->forceDelete();

    $result = $this->repo->updateOrCreate($attributes, ['name' => 'Novo', 'stock' => 3]);

    // Antes: o cache dizia que existia, então ia para update() num id que não
    // existe mais e devolvia "não aconteceu".
    expect($result)->toBeInstanceOf(Product::class)
        ->and($result->stock)->toBe(3)
        ->and($result->name)->toBe('Novo');
});
