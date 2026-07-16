<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Events;

class RepositoryDeleted extends RepositoryEvent
{

    public function __construct($repository, $model, array $data = [], string $action = 'deleted', public bool $wasSoftDelete = true)
    {
        parent::__construct($repository, $model, $data, $action);
    }
}
