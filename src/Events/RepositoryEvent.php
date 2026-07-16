<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Events;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;
use RiseTechApps\Repository\Core\BaseRepository;

abstract class RepositoryEvent
{
    use Dispatchable;

    public function __construct(public BaseRepository $repository, public ?Model $model = null, public array $data = [], public string $action = '')
    {

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
