<?php

namespace RiseTechApps\Repository\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use RiseTechApps\Repository\Core\BaseRepository;

class RepositoryBeforeClearingCacheEvent
{
    use Dispatchable;

    public BaseRepository $repository;
    public ?Model $model;

    public function __construct(BaseRepository $repository, ?Model $model = null)
    {
        $this->repository = $repository;
        $this->model = $model;
    }

    /**
     * Retorna o nome da entidade.
     */
    public function getEntityName(): string
    {
        return $this->repository->getEntityClassName();
    }

    /**
     * Retorna o ID do model se existir.
     */
    public function getModelId(): string|int|null
    {
        return $this->model?->getKey();
    }
}
