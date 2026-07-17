<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RiseTechApps\Repository\Repository;

/**
 * Invalida o cache do repositório (core + cacheResponse) em QUALQUER escrita
 * Eloquent do model — passando ou não pelo repositório (Furo 3).
 *
 * Adicione ao model cujo cache precisa ser sempre consistente:
 *
 *   use RiseTechApps\Repository\Traits\InvalidatesRepositoryCache;
 *
 *   class Client extends Model
 *   {
 *       use InvalidatesRepositoryCache;
 *   }
 *
 * Cobre `save()`, `create()`, `update()`, `delete()` e, em models com
 * SoftDeletes, `restore()`/`forceDelete()` — inclusive quando disparados
 * diretamente no model (fora do repositório).
 *
 * NÃO cobre escritas que pulam os eventos Eloquent — `DB::table()->update()`,
 * bulk `Model::where()->update()`/`->delete()`. Nesses casos, chame
 * manualmente `Repository::flushEntity(static::class)` após a operação.
 *
 * A invalidação é LEVE (apenas o flush das tags da entidade). Não dispara os
 * jobs de warming/refresh do BaseRepository::clearCacheForEntity() — assim um
 * loop salvando muitos models não gera uma enxurrada de jobs.
 */
trait InvalidatesRepositoryCache
{
    public static function bootInvalidatesRepositoryCache(): void
    {
        $flush = static function (Model $model): void {
            Repository::flushEntity($model::class);
        };

        static::saved($flush);
        static::deleted($flush);

        // restored/forceDeleted só existem com SoftDeletes.
        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored($flush);
            static::forceDeleted($flush);
        }
    }
}
