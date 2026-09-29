<?php

use Illuminate\Support\Facades\DB;
use RiseTechApps\Repository\Tests\Fixtures\Product;
use RiseTechApps\Repository\Tests\Fixtures\ProductEloquentRepository;

/**
 * applySearch() é protected: os repositories concretos o chamam de dentro de
 * queries próprias (ex.: listagem com JOIN). O helper expõe só para o teste.
 */
function searchRepository(bool $unaccent = false): ProductEloquentRepository
{
    return new class($unaccent) extends ProductEloquentRepository {
        public function __construct(bool $unaccent)
        {
            parent::__construct();
            $this->searchUnaccent = $unaccent;
        }

        public function search($query, ?string $term, array $fields): void
        {
            $this->applySearch($query, $term, $fields);
        }
    };
}

beforeEach(function () {
    Product::create(['name' => 'Mateus Soares Reis', 'sku' => 'A-1']);
    Product::create(['name' => 'Joana Reis', 'sku' => 'B-50%']);
    Product::create(['name' => 'Outro', 'sku' => 'C-1']);
});

it('matches every word in any order across string fields', function () {
    $query = Product::query();

    searchRepository()->search($query, 'reis mateus', ['name', 'sku']);

    expect($query->pluck('name')->all())->toBe(['Mateus Soares Reis']);
});

it('uses an Expression field literally instead of turning it into a JSON path', function () {
    $query = Product::query();

    searchRepository()->search($query, 'mateus', [DB::raw('products.name')]);

    expect($query->toSql())->toContain('products.name')
        ->not->toContain('->>')
        ->and($query->pluck('name')->all())->toBe(['Mateus Soares Reis']);
});

it('searches qualified Expression columns in a query with JOIN', function () {
    // Self-join só para ter duas tabelas no escopo com a mesma coluna `name`:
    // sem a coluna qualificada o banco acusaria ambiguidade.
    $query = Product::query()
        ->join('products as p2', 'p2.id', '=', 'products.id')
        ->select('products.*');

    searchRepository()->search($query, 'joana', [DB::raw('products.sku'), DB::raw('p2.name')]);

    expect($query->pluck('products.name')->all())->toBe(['Joana Reis']);
});

it('ignores searchUnaccent outside PostgreSQL', function () {
    if (\RiseTechApps\Repository\Tests\TestCase::onPostgres()) {
        test()->markTestSkipped('Cenário exclusivo de driver não-PostgreSQL.');
    }

    $query = Product::query();

    searchRepository(unaccent: true)->search($query, 'mateus', ['name', DB::raw('products.sku')]);

    expect($query->toSql())->not->toContain('immutable_unaccent')
        ->and($query->pluck('name')->all())->toBe(['Mateus Soares Reis']);
});

it('finds accented values from an unaccented term through an Expression column', function () {
    requiresPostgres();

    DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');
    DB::statement(<<<'SQL'
        CREATE OR REPLACE FUNCTION immutable_unaccent(text)
        RETURNS text AS $$
            SELECT unaccent('unaccent', $1)
        $$ LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
    SQL);

    Product::create(['name' => 'ÁLCOOL 70% GEL', 'sku' => 'D-1']);

    $query = Product::query();

    searchRepository(unaccent: true)->search($query, 'alcool gel', [DB::raw('products.name')]);

    expect($query->pluck('name')->all())->toBe(['ÁLCOOL 70% GEL']);
});
