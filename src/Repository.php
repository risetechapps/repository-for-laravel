<?php

namespace RiseTechApps\Repository;

use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\Facades\Cache;

class Repository
{
    /** Fallback de config('repository.cache.unsupported_tag_drivers') — manter alinhado com config/config.php. */
    public static array $driverNotSupported = ["file", "database", "dynamodb"];
    public static string $methodFirst = 'FIRST';
    public static string $methodAll = 'ALL';
    public static string $methodFind = 'FIND';
    public static string $methodFindWhere = 'FIND_WHERE';
    public static string $methodFindWhereCustom = 'FIND_WHERE_CUSTOM';
    public static string $methodFindWhereEmail = 'FIND_WHERE_EMAIL';
    public static string $methodFindWhereFirst = 'FIND_WHERE_FIRST';
    public static string $methodDataTable = 'DATATABLE';
    public static string $methodOrder = 'ORDER';
    public static string $methodPaginate = 'PAGINATE';
    public static array $tagsCache = [];

    public static function setTagsCache(string $tag): void
    {
        self::$tagsCache[] = $tag;
    }

    public static function getTagsCache(): array
    {
        return self::$tagsCache;
    }

    /**
     * Nome do store de cache do repositório (config repository.cache.store).
     * null = store default da aplicação.
     */
    public static function cacheStoreName(): ?string
    {
        return config('repository.cache.store');
    }

    /**
     * Store de cache usado pelo core e pelo middleware cacheResponse.
     * Fonte única — garante que leitura, escrita e invalidação usem o MESMO
     * store, mesmo quando o default da app é diferente (Furo 2).
     */
    public static function store(): CacheRepository
    {
        return Cache::store(static::cacheStoreName());
    }

    /**
     * Indica se o store do repositório suporta tags. Resolve o driver do store
     * configurado (não o default da app) e checa contra a lista de drivers sem
     * suporte a tags (config repository.cache.unsupported_tag_drivers).
     */
    public static function storeSupportsTags(): bool
    {
        $name = static::cacheStoreName() ?? config('cache.default');
        $driver = config("cache.stores.{$name}.driver", $name);

        $unsupported = config('repository.cache.unsupported_tag_drivers', self::$driverNotSupported);

        return !in_array($driver, $unsupported, true);
    }

    /**
     * Invalidação LEVE do cache de uma entidade (core + cacheResponse), sem os
     * jobs de warming/refresh do BaseRepository::clearCacheForEntity().
     *
     * É o ponto usado pela trait InvalidatesRepositoryCache (Furo 3): qualquer
     * escrita Eloquent do model — passando ou não pelo repositório — invalida o
     * cache. Também pode ser chamado manualmente após uma escrita crua
     * (DB::table()->update(), bulk update) que não dispara eventos Eloquent.
     *
     *   Repository::flushEntity(\App\Models\Client::class);
     *
     * No-op quando o store não suporta tags.
     */
    public static function flushEntity(string $modelClass): void
    {
        if (!static::storeSupportsTags()) {
            return;
        }

        $tag = ltrim($modelClass, '\\');
        $apiResponseTag = str_replace('\\', '.', $tag);

        static::store()->tags([$tag, $apiResponseTag])->flush();
    }

    public static function getBindingsRepository(): array
    {
        $allBindings = app()->getBindings();

        $repositoryContracts = [];

        foreach (array_keys($allBindings) as $contractName) {
            if (str_contains((string) $contractName, 'Repository') && is_subclass_of($contractName,\RiseTechApps\Repository\Contracts\RepositoryInterface::class)) {
                $repositoryContracts[] = $contractName;
            }
        }

        return $repositoryContracts;
    }
}
