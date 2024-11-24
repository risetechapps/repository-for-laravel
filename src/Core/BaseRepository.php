<?php

namespace RiseTechApps\Repository\Core;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use RiseTechApps\Repository\Contracts\RepositoryInterface;
use RiseTechApps\Repository\Exception\NotEntityDefinedException;
use RiseTechApps\Repository\Jobs\RegenerateCacheJob;
use RiseTechApps\Repository\Repository;

abstract class BaseRepository implements RepositoryInterface
{
    public $entity;
    protected Carbon $tll;
    protected $driver;
    protected bool $supportTag = false;
    protected string $permission = '';
    protected $relationships = [];
    protected string|int $id;
    protected bool $hasContainsSoftDelete = false;

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
        if ($this->hasContainsSoftDelete) {
            if (auth()->check() && auth()->user()->hasPermission($this->permission)) {
                return true;
            }
        }
        return false;
    }

    public function containsSoftDelete(): bool
    {
        return collect(class_uses_recursive($this->entity))->contains(SoftDeletes::class);
    }

    private function getQualifyTagCache(string $method, array $parameters = []): string
    {
        $entityClass = get_class($this->entity);
        $paramsHash = !empty($parameters) ? '_' . md5(json_encode($parameters)) : '';
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
        }

        try{
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
        }catch(\Exception $exception){

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
        return $this->rememberCache(function () {
            return $this->applySoftDeletes($this->entity)->first();
        }, Repository::$methodFirst);
    }

    public function get()
    {
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

    public function findWhere($column, $valor)
    {
        return $this->rememberCache(function () use ($column, $valor) {
            return $this->applySoftDeletes($this->entity)->where($column, $valor)->get();
        }, Repository::$methodFindWhere, [$column, $valor]);
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

        $id = $model->getKey();

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

    public function useTrashed(string $permission): static
    {
        $this->permission = $permission;
        return $this;
    }

    private function applySoftDeletes($query)
    {
        return $this->Trashed() ? $query->withTrashed(true) : $query;
    }
}
