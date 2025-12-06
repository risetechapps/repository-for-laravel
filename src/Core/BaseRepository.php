<?php

namespace RiseTechApps\Repository\Core;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RiseTechApps\Repository\Contracts\RepositoryInterface;
use RiseTechApps\Repository\Events\AfterRefreshMaterializedViewsJobEvent;
use RiseTechApps\Repository\Events\BeforeRefreshMaterializedViewsJobEvent;
use RiseTechApps\Repository\Exception\NotEntityDefinedException;
use RiseTechApps\Repository\Jobs\RefreshMaterializedViewsJob;
use RiseTechApps\Repository\Jobs\RegenerateCacheJob;
use RiseTechApps\Repository\Repository;
use SebastianBergmann\Template\RuntimeException;

abstract class BaseRepository implements RepositoryInterface
{
    protected ?string $activeView = null;

    public $entity;
    protected Carbon $tll;
    protected $driver;
    protected bool $supportTag = false;
    protected string|bool $permission = false;
    protected $relationships = [];
    protected string|int $id;
    protected bool $hasContainsSoftDelete = false;
    protected array $tags = [];

    /**
     * @throws NotEntityDefinedException
     */
    public function __construct()
    {
        $this->entity = $this->resolveEntity();
        $this->hasContainsSoftDelete = $this->containsSoftDelete();
        $this->tll = Carbon::now()->addHours(24);
        $this->driver = Cache::getDefaultDriver();
        $this->supportTag = !in_array($this->driver, Repository::$driverNotSupported);
    }

    /**
     * @throws NotEntityDefinedException
     */
    public function resolveEntity(): mixed
    {
        if (!method_exists($this, 'entity')) {
            throw new NotEntityDefinedException;
        }
        return app($this->entity());
    }

    public function Trashed(): bool
    {
        try {
            if ($this->hasContainsSoftDelete) {

                if (app()->runningInConsole()) {
                    return $this->permission;
                }

                if (is_string($this->permission)) {
                    if (!auth()->check()) return false;
                    if (auth()->user()->hasPermission($this->permission) === true) {
                        return true;
                    } else {
                        return false;
                    }
                } else if (is_bool($this->permission)) {
                    return $this->permission;
                }
            }
            return false;
        } catch (\Exception|RuntimeException|\Throwable $e) {
            return false;
        }
    }

    public function containsSoftDelete(): bool
    {
        return collect(class_uses_recursive($this->entity))->contains(SoftDeletes::class);
    }

    private function getQualifyTagCache(string $method, array $parameters = []): string
    {
        $entityClass = get_class($this->entity);
        $paramsHash = !empty($parameters) ? '_' . md5(json_encode($parameters)) : '';

        $contexts = $this->tags;
        if (!empty($contexts)) {
            $paramsHash .= '_' . md5(json_encode($contexts));
        }

        $name = $entityClass . DIRECTORY_SEPARATOR . $method . $paramsHash;

        if ($this->Trashed()) $name .= '_TRASHED';

        return $name;
    }

    public function rememberCache(callable $call, string $method, array $parameters = [])
    {
        $cacheKey = $this->getQualifyTagCache($method, $parameters);

        if ($this->supportTag) {
            return Cache::tags([$this->entity()])->remember($cacheKey, $this->tll, $call);
        } else {
            return Cache::remember($cacheKey, $this->tll, $call);
        }
    }

    public function clearCacheForEntity(string $method = '', array $parameters = []): void
    {
        if ($this->supportTag) {
            Cache::tags([get_class($this->entity)])->flush();

            $apiResponseTag = str_replace('\\', '.', $this->entity);
            Cache::tags([$apiResponseTag])->flush();
            Cache::tags('api_response')->flush();
        }

        try {
            dispatch(new RegenerateCacheJob($this, [
                Repository::$methodFirst,
                Repository::$methodAll,
                Repository::$methodFind,
                Repository::$methodFindWhere,
                Repository::$methodFindWhereEmail,
                Repository::$methodFindWhereFirst,
                Repository::$methodDataTable,
                Repository::$methodOrder,
            ], $parameters));

            //refresh views

            dispatch(new RefreshMaterializedViewsJob($this, ['auth' => auth()->user()]));
        } catch (\Exception $exception) {

        }

        $this->clearParameterizedCaches($method, $parameters);
    }

    private function clearParameterizedCaches(string $method, array $parameters): void
    {
        $paramsHash = !empty($parameters) ? '_' . md5(json_encode($parameters)) : '';
        $cacheKeyPattern = DIRECTORY_SEPARATOR . $method . $paramsHash;

        if ($this->supportTag) {
            Cache::tags([get_class($this->entity)])->forget($cacheKeyPattern);
            Cache::tags([get_class($this->entity)])->flush();
        }
    }

    public function find($id): static
    {
        $this->id = $id;
        return $this;
    }

    public function first()
    {
        if ($this->activeView) {
            return collect(DB::table($this->activeView)->first());
        }
        return $this->rememberCache(function () {
            return $this->applySoftDeletes($this->entity)->first();
        }, Repository::$methodFirst);
    }

    public function get()
    {
        if ($this->activeView) {
            return collect(DB::table($this->activeView)->get());
        }
        return $this->rememberCache(function () {
            return $this->applySoftDeletes($this->entity)->get();
        }, Repository::$methodAll);
    }

    public function findById($id)
    {
        return $this->rememberCache(function () use ($id) {
            return $this->applySoftDeletes($this->entity)->find($id);
        }, Repository::$methodFind, [$id]);
    }

    public function findWhere(array $conditions)
    {
        return $this->rememberCache(function () use ($conditions) {
            $query = $this->applySoftDeletes($this->entity);

            foreach ($conditions as $column => $value) {
                $query = $query->where($column, $value);
            }
            return $query->get();
        }, Repository::$methodFindWhere, [$conditions]);
    }

    public function findWhereCustom(array $conditions)
    {
        return $this->rememberCache(function () use ($conditions) {
            $query = $this->applySoftDeletes($this->entity);

            foreach ($conditions as $filter) {
                $this->applyCustomFilter($query, $filter);
            }

            return $query->get();
        }, Repository::$methodFindWhereCustom, [$conditions]);
    }

    private function applyCustomFilter(&$query, array $filter, $isOr = false): void
    {
        if (isset($filter['orGroup']) && is_array($filter['orGroup'])) {
            $query = $query->where(function ($q) use ($filter) {
                foreach ($filter['orGroup'] as $groupFilter) {
                    $this->applyCustomFilter($q, $groupFilter, true);
                }
            });
            return;
        }

        if (isset($filter['andGroup']) && is_array($filter['andGroup'])) {
            $query = $query->where(function ($q) use ($filter) {
                foreach ($filter['andGroup'] as $groupFilter) {
                    $this->applyCustomFilter($q, $groupFilter, false);
                }
            });
            return;
        }

        // filtros simples
        if (!isset($filter['column'], $filter['operator'])) {
            return;
        }

        $column = $filter['column'];
        $operator = strtoupper($filter['operator']);
        $value = $filter['value'] ?? null;

        // BETWEEN
        if ($operator === 'BETWEEN' && is_array($value) && count($value) === 2) {
            $isOr ? $query = $query->orWhereBetween($column, $value) : $query = $query->whereBetween($column, $value);
            return;
        }

        // IN
        if ($operator === 'IN' && is_array($value)) {
            $isOr ? $query = $query->orWhereIn($column, $value) : $query = $query->whereIn($column, $value);
            return;
        }

        // IS NULL / IS NOT NULL
        if ($operator === 'IS' && $value === null) {
            $isOr ? $query = $query->orWhereNull($column) : $query = $query->whereNull($column);
            return;
        }

        if ($operator === 'IS NOT' && $value === null) {
            $isOr ? $query = $query->orWhereNotNull($column) : $query = $query->whereNotNull($column);
            return;
        }

        // LIKE
        if ($operator === 'LIKE') {
            $isOr
                ? $query = $query->orWhere($column, 'LIKE', "%{$value}%")
                : $query = $query->where($column, 'LIKE', "%{$value}%");
            return;
        }

        // Default
        $isOr
            ? $query = $query->orWhere($column, $operator, $value)
            : $query = $query->where($column, $operator, $value);
    }

    public function findWhereEmail($valor)
    {
        return $this->rememberCache(function () use ($valor) {
            return $this->applySoftDeletes($this->entity)->where('email', $valor)->get();
        }, Repository::$methodFindWhereEmail, [$valor]);
    }

    public function findWhereFirst($column, $valor)
    {
        return $this->rememberCache(function () use ($column, $valor) {
            return $this->applySoftDeletes($this->entity)->where($column, $valor)->first();
        }, Repository::$methodFindWhereFirst, [$column, $valor]);
    }

    public function store(array $data)
    {
        $created = $this->entity->create($data);
        $this->clearCacheForEntity();
        return $created;
    }

    public function update($id, array $data)
    {
        $updated = $this->findById($id)->update($data);
        $this->clearCacheForEntity();

        return $updated;
    }

    public function createOrUpdate($id, array $data)
    {
        if ($this->findById($id) == null) {
            return $this->store($data);
        } else {
            return $this->update($id, $data);
        }
    }

    public function delete(): bool
    {
        $model = $this->entity->find($this->id);

        if (!$model) return false;

        foreach ($this->relationships as $relationship) {
            $model->$relationship()->delete();
        }

        $deleted = $model->delete();

        $this->clearCacheForEntity();

        return $deleted;
    }

    public function restore(): bool
    {
        $model = $this->entity->withTrashed(true)->find($this->id);
        $id = $model->getKey();

        foreach ($this->relationships as $relationship) {
            $model->$relationship()->restore();
        }

        $restored = $model->restore();

        $this->clearCacheForEntity();

        return $restored;
    }

    public function forceDelete(): bool
    {
        $restored = false;

        $model = $this->entity->withTrashed(true)->find($this->id);
        $id = $model->getKey();

        if ($model->trashed()) {
            foreach ($this->relationships as $relationship) {
                $model->$relationship()->whereNotNull('deleted_at')->forceDelete();
            }

            $restored = $model->forceDelete();
        }

        $this->clearCacheForEntity();

        return $restored;
    }

    public function paginate($totalPage = 10): array
    {
        $data = $this->Trashed() ? $this->entity->withTrashed(true)->paginate($totalPage) :
            $this->entity->paginate($totalPage);

        return [
            'data' => $data->items(),
            'recordsFiltered' => 0,
            'recordsTotal' => $data->total(),
            'totalPages' => $data->lastPage(),
            'perPage' => $data->perPage()
        ];
    }

    public function dataTable()
    {
        return $this->rememberCache(function () {
            return $this->applySoftDeletes($this->entity)->get();
        }, Repository::$methodDataTable);
    }

    public function orderBy($column, $order = 'DESC')
    {
        if (mb_strtoupper($order) != 'DESC' && mb_strtoupper($order) != 'ASC') {
            $order = 'ASC';
        }

        return $this->rememberCache(function () use ($column, $order) {
            return $this->applySoftDeletes($this->entity)->orderBy($column, $order)->get();
        }, Repository::$methodOrder);
    }

    public function relationships(...$relationships): static
    {

        $this->relationships = [];

        foreach ($relationships as $relationship) {

            if ($this->hasContainsSoftDelete && $this->Trashed()) {

                $this->relationships[$relationship] = function ($query) {
                    $query->withTrashed();
                };
            } else {
                $this->relationships[] = $relationship;
            }
        }

        $this->entity = $this->entity->with($this->relationships);

        return $this;
    }

    public function useTrashed(string|bool $permission): static
    {
        $this->permission = $permission;
        return $this;
    }

    private function applySoftDeletes($query)
    {
        return $this->Trashed() ? $query->withTrashed(true) : $query;
    }

    public function setTags($tags): static
    {
        if ($this->supportTag) {
            $this->tags = $tags;
        }
        return $this;
    }

    public function createMaterializedViews(): void
    {

        foreach ($this->registerViews() as $view => $query) {

            if (!$this->materializedViewExists($view)) {
                $this->createSingleMaterializedView($view, $query);
            }
        }
    }

    protected function createSingleMaterializedView(string $view, string $query): void
    {
        try {

            DB::statement("CREATE MATERIALIZED VIEW {$view} AS {$query}");
            DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$view}_idx ON {$view}(id)");
            DB::table('materialized_views')->updateOrInsert(
                ['name' => $view],
                [
                    'user_id' => auth()->id(),
                    'created_at' => now(),
                    'last_refreshed_at' => now(),
                    'active' => true,
                ]
            );
        } catch (\Throwable $e) {
            dd($e);
        }
    }

    protected function materializedViewExists(string $view): bool
    {
        $result = DB::select("
        SELECT 1 FROM pg_matviews WHERE schemaname = 'public' AND matviewname = ?
    ", [$view]);

        return !empty($result);
    }

    public function refreshMaterializedViews(?string $view = null, bool $concurrently = true): void
    {
        $this->createMaterializedViews();

        $views = $view ? [$view] : array_keys($this->registerViews());

        foreach ($views as $v) {
            try {
                event(new AfterRefreshMaterializedViewsJobEvent($v));
                $sql = $concurrently
                    ? "REFRESH MATERIALIZED VIEW CONCURRENTLY {$v};"
                    : "REFRESH MATERIALIZED VIEW {$v};";

                DB::statement($sql);

                DB::table('materialized_views')
                    ->where('name', $v)
                    ->update(['last_refreshed_at' => now()]);

                event(new BeforeRefreshMaterializedViewsJobEvent($v));

            } catch (\Throwable $e) {
                dd($e);
            }
        }
    }

    public function useMaterializedView(string $view): static
    {
        $this->activeView = $view;
        return $this;
    }
}
