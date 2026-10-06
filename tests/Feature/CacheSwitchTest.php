<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use RiseTechApps\Repository\Events\RepositoryBeforeClearingCacheEvent;
use RiseTechApps\Repository\Jobs\RegenerateCacheJob;
use RiseTechApps\Repository\Repository;
use RiseTechApps\Repository\Tests\Fixtures\Product;
use RiseTechApps\Repository\Tests\Fixtures\ProductEloquentRepository;

beforeEach(function () {
    config()->set('repository.cache.unsupported_tag_drivers', ['file', 'database']);
    Cache::flush();
    $this->repo = new ProductEloquentRepository();
});

function ttlMinutes(ProductEloquentRepository $repo): int
{
    Carbon::setTestNow('2026-01-01 00:00:00');

    $ttl = (new ReflectionMethod($repo, 'getCacheTtl'))->invoke($repo);
    $minutes = (int) Carbon::now()->diffInMinutes($ttl);

    Carbon::setTestNow();

    return $minutes;
}

// ---------------------------------------------------------------------------
// Chave geral (repository.cache.enabled)
// ---------------------------------------------------------------------------

it('caches reads while the global switch is on', function () {
    expect($this->repo->get())->toBeEmpty();

    Product::create(['name' => 'X']); // por fora do repositório: não invalida

    expect($this->repo->get())->toBeEmpty();
});

it('goes straight to the database when the global switch is off', function () {
    config()->set('repository.cache.enabled', false);

    expect($this->repo->get())->toBeEmpty();

    Product::create(['name' => 'X']);

    expect($this->repo->get())->toHaveCount(1)
        ->and($this->repo->isCacheEnabled())->toBeFalse();
});

it('keeps the cache API callable with the switch off', function () {
    config()->set('repository.cache.enabled', false);

    Product::create(['name' => 'X']);

    expect($this->repo->cacheFor(5)->get())->toHaveCount(1)
        ->and($this->repo->withoutCache()->count())->toBe(1);

    $this->repo->flushTags(['qualquer']);
    $this->repo->warmCache(['get', 'first']);
    Repository::flushEntity(Product::class);
});

it('does not fire clearing events nor warming jobs with the switch off', function () {
    config()->set('repository.cache.enabled', false);
    config()->set('repository.cache.warming_enabled', true);
    Event::fake([RepositoryBeforeClearingCacheEvent::class]);
    Bus::fake([RegenerateCacheJob::class]);

    $this->repo->store(['name' => 'X']);

    Event::assertNotDispatched(RepositoryBeforeClearingCacheEvent::class);
    Bus::assertNotDispatched(RegenerateCacheJob::class);
});

it('bypasses cacheResponse with the switch off', function () {
    config()->set('repository.cache.enabled', false);

    $calls = 0;
    Route::middleware('cacheResponse:3600')->get('/switch-off', function () use (&$calls) {
        return response()->json(['calls' => ++$calls]);
    });

    $this->get('/switch-off');
    $second = $this->get('/switch-off');

    expect($second->json('calls'))->toBe(2)
        ->and($second->headers->has('X-Cached-By'))->toBeFalse();
});

// ---------------------------------------------------------------------------
// Por repositório ($cacheEnabled)
// ---------------------------------------------------------------------------

it('lets a single repository opt out of the cache', function () {
    $uncached = new class extends ProductEloquentRepository {
        protected bool $cacheEnabled = false;
    };

    expect($uncached->get())->toBeEmpty()
        ->and($this->repo->get())->toBeEmpty();

    Product::create(['name' => 'X']);

    // O desligado enxerga na hora; o normal continua servindo o cache.
    expect($uncached->get())->toHaveCount(1)
        ->and($this->repo->get())->toBeEmpty();
});

// ---------------------------------------------------------------------------
// Warming
// ---------------------------------------------------------------------------

it('does not dispatch warming jobs by default', function () {
    Bus::fake([RegenerateCacheJob::class]);

    $this->repo->store(['name' => 'X']);

    Bus::assertNotDispatched(RegenerateCacheJob::class);
});

it('still dispatches warming jobs when explicitly enabled', function () {
    config()->set('repository.cache.warming_enabled', true);
    Bus::fake([RegenerateCacheJob::class]);

    $this->repo->store(['name' => 'X']);

    Bus::assertDispatched(RegenerateCacheJob::class);
});

// ---------------------------------------------------------------------------
// TTL
// ---------------------------------------------------------------------------

it('uses repository.cache.default_ttl as the default TTL', function () {
    config()->set('repository.cache.default_ttl', 15);

    expect(ttlMinutes($this->repo))->toBe(15);
});

it('falls back to 24h when default_ttl is null', function () {
    config()->set('repository.cache.default_ttl', null);

    expect(ttlMinutes($this->repo))->toBe(1440);
});

it('lets a repository that overrides defaultCacheTtlMinutes win over the config', function () {
    config()->set('repository.cache.default_ttl', 15);

    $repo = new class extends ProductEloquentRepository {
        protected int $defaultCacheTtlMinutes = 60;
    };

    expect(ttlMinutes($repo))->toBe(60);
});

it('lets cacheFor() win over everything', function () {
    config()->set('repository.cache.default_ttl', 15);

    expect(ttlMinutes($this->repo->cacheFor(3)))->toBe(3);
});

// ---------------------------------------------------------------------------
// Invalidação depois do refresh das views
// ---------------------------------------------------------------------------

it('invalidates the entity cache after refreshing the materialized views', function () {
    expect($this->repo->get())->toBeEmpty();

    Product::create(['name' => 'X']); // cache agora está velho

    Event::fake([RepositoryBeforeClearingCacheEvent::class]);

    $this->repo->refreshMaterializedViews();

    // Ouvintes (ex.: tenancy) são avisados para limpar as outras gavetas.
    Event::assertDispatched(RepositoryBeforeClearingCacheEvent::class);

    expect($this->repo->get())->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// cacheResponse por usuário
// ---------------------------------------------------------------------------

it('keys cacheResponse per authenticated user by default', function () {
    Route::middleware('cacheResponse:3600')->get('/me-default', fn() => response()->json([
        'user' => request()->user()?->getAuthIdentifier(),
    ]));

    $this->actingAs(new GenericUser(['id' => 1]))->get('/me-default');
    $other = $this->actingAs(new GenericUser(['id' => 2]))->get('/me-default');

    expect($other->json('user'))->toBe(2);
});

it('shares cacheResponse between users when scope is public', function () {
    $calls = 0;
    Route::middleware('cacheResponse:3600,,public')->get('/shared', function () use (&$calls) {
        return response()->json(['calls' => ++$calls]);
    });

    $this->actingAs(new GenericUser(['id' => 1]))->get('/shared');
    $other = $this->actingAs(new GenericUser(['id' => 2]))->get('/shared');

    expect($other->json('calls'))->toBe(1)
        ->and($other->headers->get('X-Cached-By'))->toBe('cache-response-api');
});
