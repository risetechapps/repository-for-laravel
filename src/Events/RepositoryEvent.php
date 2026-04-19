<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use RiseTechApps\Repository\Core\BaseRepository;

abstract class RepositoryEvent
{
    use Dispatchable;

    public BaseRepository $repository;
    public ?Model $model;
    public array $data;
    public string $action;

    public function __construct(BaseRepository $repository, ?Model $model = null, array $data = [], string $action = '')
    {
        $this->repository = $repository;
        $this->model = $model;
        $this->data = $data;
        $this->action = $action;
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
