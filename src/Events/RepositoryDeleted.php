<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Events;

class RepositoryDeleted extends RepositoryEvent
{
    /**
     * Indica se foi soft delete ou hard delete.
     */
    public bool $wasSoftDelete;

    public function __construct($repository, $model, array $data = [], string $action = 'deleted', bool $wasSoftDelete = true)
    {
        parent::__construct($repository, $model, $data, $action);
        $this->wasSoftDelete = $wasSoftDelete;
    }
}
