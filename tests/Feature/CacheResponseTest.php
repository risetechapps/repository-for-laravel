<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use RiseTechApps\Repository\Repository;
use RiseTechApps\Repository\Tests\Fixtures\Product;
use RiseTechApps\Repository\Tests\Fixtures\ProductEloquentRepository;

beforeEach(function () {
    // O TestCase usa o store 'array'. O ArrayStore suporta tags em memória —
    // destrava aqui para exercitar a invalidação por tag do cacheResponse.
    config()->set('repository.cache.unsupported_tag_drivers', ['file', 'database']);
    // Sem jobs de warming no caminho de escrita durante os testes.
    config()->set('repository.cache.warming_enabled', false);

    Cache::flush();
});

it('caches a GET response and serves the hit with X-Cached-By', function () {
    $calls = 0;
    Route::middleware('cacheResponse:3600')->get('/cache-basic', function () use (&$calls) {
        $calls++;
        return response()->json(['calls' => $calls]);
    });

    $first = $this->get('/cache-basic');
    $second = $this->get('/cache-basic');

    $first->assertOk();
    $second->assertOk()->assertHeader('X-Cached-By', 'cache-response-api');

    // O controller rodou uma única vez; a 2ª resposta veio do cache.
    expect($first->json('calls'))->toBe(1)
        ->and($second->json('calls'))->toBe(1);
});

it('does not cache non-GET requests', function () {
    $calls = 0;
    Route::middleware('cacheResponse:3600')->post('/cache-post', function () use (&$calls) {
        $calls++;
        return response()->json(['calls' => $calls]);
    });

    $this->post('/cache-post');
    $second = $this->post('/cache-post');

    // Rodou toda vez — POST não é cacheado.
    expect($second->json('calls'))->toBe(2)
        ->and($second->headers->has('X-Cached-By'))->toBeFalse();
});

it('varies the cache key by query string', function () {
    Route::middleware('cacheResponse:3600')->get('/cache-qs', fn() => response()->json([
        'q' => request('page'),
    ]));

    $p1 = $this->get('/cache-qs?page=1');
    $p2 = $this->get('/cache-qs?page=2');

    expect($p1->json('q'))->toBe('1')
        ->and($p2->json('q'))->toBe('2');
});

it('does not cache the Set-Cookie header', function () {
    Route::middleware('cacheResponse:3600')->get('/cache-cookie', fn() => response()
        ->json(['ok' => true])
        ->withCookie(cookie('sess', 'secret', 60)));

    $first = $this->get('/cache-cookie');
    $second = $this->get('/cache-cookie');

    // 1ª resposta (não cacheada) traz o cookie...
    expect($first->headers->has('set-cookie'))->toBeTrue();

    // ...mas o hit do cache NÃO — não vaza sessão entre usuários.
    $second->assertHeader('X-Cached-By', 'cache-response-api');
    expect($second->headers->has('set-cookie'))->toBeFalse();
});

it('invalidates the cached response when the repository writes the entity', function () {
    Route::middleware('cacheResponse:3600,' . Product::class)
        ->get('/products-count', fn() => response()->json(['count' => Product::count()]));

    Product::create(['name' => 'A']);

    $first = $this->get('/products-count');
    expect($first->json('count'))->toBe(1);

    // Confirma que está cacheado.
    $this->get('/products-count')->assertHeader('X-Cached-By', 'cache-response-api');

    // Escrita via repositório → flushEntityCache → Repository::flushEntity(Product).
    (new ProductEloquentRepository())->store(['name' => 'B']);

    $after = $this->get('/products-count');

    // Recomputado (miss): novo valor e sem X-Cached-By.
    expect($after->json('count'))->toBe(2)
        ->and($after->headers->has('X-Cached-By'))->toBeFalse();
});

it('accepts a dotted entityTag and still invalidates on write', function () {
    $dotted = str_replace('\\', '.', Product::class);

    Route::middleware('cacheResponse:3600,' . $dotted)
        ->get('/products-dotted', fn() => response()->json(['count' => Product::count()]));

    Product::create(['name' => 'A']);
    $this->get('/products-dotted');

    (new ProductEloquentRepository())->store(['name' => 'B']);

    expect($this->get('/products-dotted')->json('count'))->toBe(2);
});

it('does not invalidate the response when a different entity is flushed', function () {
    Route::middleware('cacheResponse:3600,' . Product::class)
        ->get('/products-scope', fn() => response()->json(['count' => Product::count()]));

    Product::create(['name' => 'A']);
    $this->get('/products-scope'); // cacheia count=1

    // Insere fora do repositório (não invalida) e força o flush de OUTRA entidade.
    Product::create(['name' => 'B']); // DB agora tem 2, mas o cache segue 1
    Repository::flushEntity('App\\Models\\Other');

    $again = $this->get('/products-scope');

    // Cache de Product intacto — o flush da outra entidade não o derrubou.
    $again->assertHeader('X-Cached-By', 'cache-response-api');
    expect($again->json('count'))->toBe(1);
});
