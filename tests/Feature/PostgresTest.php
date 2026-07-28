<?php

use Illuminate\Support\Facades\DB;
use RiseTechApps\Repository\Tests\Fixtures\Product;
use RiseTechApps\Repository\Tests\Fixtures\ProductEloquentRepository;
use RiseTechApps\Repository\Tests\TestCase;

/**
 * Recursos que só existem no PostgreSQL. Em sqlite todos são pulados —
 * é o job `postgres` da CI que os executa de verdade.
 */
beforeEach(function () {
    requiresPostgres();

    $this->repo = new ProductEloquentRepository();

    DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
});

afterEach(function () {
    // afterEach roda mesmo quando o beforeEach pulou o teste — em sqlite não há
    // o que limpar (e DROP MATERIALIZED VIEW nem existe).
    if (! TestCase::onPostgres()) {
        return;
    }

    // Uma matview dependente de `products` impede o rollback das migrations.
    foreach (['vw_products_active', 'vw_products_builder', 'vw_products_bindings', 'vw_products_injection'] as $view) {
        DB::statement("DROP MATERIALIZED VIEW IF EXISTS {$view}");
    }
});

function viewRepository(array $views): ProductEloquentRepository
{
    return new class($views) extends ProductEloquentRepository {
        public function __construct(private readonly array $views)
        {
            parent::__construct();
        }

        public function registerViews(): array
        {
            return $this->views;
        }
    };
}

it('creates a materialized view and registers it in the admin catalog', function () {
    Product::create(['name' => 'Ativo', 'status' => 'active']);
    Product::create(['name' => 'Inativo', 'status' => 'archived']);

    $repo = viewRepository([
        'vw_products_active' => "SELECT id, name FROM products WHERE status = 'active'",
    ]);

    $repo->createMaterializedViews(strict: true);

    $exists = DB::select(
        "SELECT 1 FROM pg_matviews WHERE schemaname = 'public' AND matviewname = ?",
        ['vw_products_active']
    );

    expect($exists)->not->toBeEmpty()
        ->and(DB::table('vw_products_active')->count())->toBe(1)
        ->and(DB::table('materialized_views')->where('name', 'vw_products_active')->exists())->toBeTrue();
});

it('refreshes a materialized view and updates last_refreshed_at', function () {
    Product::create(['name' => 'Primeiro', 'status' => 'active']);

    $repo = viewRepository([
        'vw_products_active' => "SELECT id, name FROM products WHERE status = 'active'",
    ]);

    $repo->createMaterializedViews(strict: true);
    expect(DB::table('vw_products_active')->count())->toBe(1);

    Product::create(['name' => 'Segundo', 'status' => 'active']);
    // Ainda o snapshot antigo: é isso que caracteriza uma matview.
    expect(DB::table('vw_products_active')->count())->toBe(1);

    DB::table('materialized_views')
        ->where('name', 'vw_products_active')
        ->update(['last_refreshed_at' => now()->subDay()]);

    // concurrently: false — CONCURRENTLY exige índice único na view.
    $repo->refreshMaterializedViews(concurrently: false, strict: true);

    expect(DB::table('vw_products_active')->count())->toBe(2)
        ->and(DB::table('materialized_views')->where('name', 'vw_products_active')->value('last_refreshed_at'))
        ->not->toBeNull();
});

it('builds a materialized view from the query builder via view()', function () {
    Product::create(['name' => 'Caro', 'status' => 'active', 'stock' => 90]);
    Product::create(['name' => 'Barato', 'status' => 'active', 'stock' => 3]);

    $definition = (new ProductEloquentRepository())->view(
        'vw_products_builder',
        fn($query) => $query->select(['id', 'name'])->where('stock', '>', 10)
    );

    $repo = viewRepository(['vw_products_builder' => $definition]);
    $repo->createMaterializedViews(strict: true);

    // Exercita o substituteBindings(): o binding 10 tem que ter entrado no SQL.
    expect(DB::table('vw_products_builder')->pluck('name')->all())->toBe(['Caro']);
});

it('creates a view whose bindings contain quote, question mark and dollar', function () {
    $alvo = "O'Brien ? R\$1.000";

    Product::create(['name' => $alvo, 'description' => 'alvo', 'status' => 'active']);
    Product::create(['name' => 'outro', 'description' => 'alvo', 'status' => 'active']);

    $definition = (new ProductEloquentRepository())->view(
        'vw_products_bindings',
        fn($query) => $query->select(['id', 'name'])
            ->where('name', $alvo)
            ->where('description', 'alvo')
    );

    // Antes: a aspa quebrava o CREATE, o "?" desalinhava o segundo binding e o
    // "$1" era comido como backreference.
    viewRepository(['vw_products_bindings' => $definition])->createMaterializedViews(strict: true);

    expect(DB::table('vw_products_bindings')->pluck('name')->all())->toBe([$alvo]);
});

it('treats an injection payload in a view binding as literal data', function () {
    Product::create(['name' => 'normal', 'status' => 'active']);
    Product::create(['name' => 'tambem normal', 'status' => 'active']);

    // Payload de instrucao unica: o protocolo de prepared statement nao barra.
    $payload = "x' union select id, name from products --";

    $definition = (new ProductEloquentRepository())->view(
        'vw_products_injection',
        fn($query) => $query->select(['id', 'name'])->where('name', $payload)
    );

    viewRepository(['vw_products_injection' => $definition])->createMaterializedViews(strict: true);

    // Nenhum produto se chama assim: se o UNION tivesse executado, a view viria
    // com os 2 produtos.
    expect(DB::table('vw_products_injection')->count())->toBe(0);
});

it('runs fuzzySearch through pg_trgm', function () {
    Product::create(['name' => 'Guilherme', 'status' => 'active']);
    Product::create(['name' => 'Zebra', 'status' => 'active']);

    $result = $this->repo->fuzzySearch('Guiherme', 'name');

    expect($result->pluck('name')->all())->toBe(['Guilherme']);
});

it('runs searchFullText through tsvector', function () {
    Product::create([
        'name' => 'Cadeira',
        'description' => 'Cadeira ergonômica de escritório com apoio lombar',
        'status' => 'active',
    ]);
    Product::create([
        'name' => 'Mesa',
        'description' => 'Mesa de jantar em madeira maciça',
        'status' => 'active',
    ]);

    $result = $this->repo->searchFullText('ergonômica', ['name', 'description']);

    expect($result->pluck('name')->all())->toBe(['Cadeira']);
});

it('queries a jsonb column through findWhereJson', function () {
    Product::create([
        'name' => 'SP',
        'status' => 'active',
        'meta' => ['endereco' => ['cidade' => 'São Paulo']],
    ]);
    Product::create([
        'name' => 'RJ',
        'status' => 'active',
        'meta' => ['endereco' => ['cidade' => 'Rio de Janeiro']],
    ]);

    $result = $this->repo->findWhereJson('meta.endereco.cidade', 'São Paulo');

    expect($result->pluck('name')->all())->toBe(['SP']);
});
