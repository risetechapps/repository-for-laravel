<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Events;

class RepositoryUpdated extends RepositoryEvent
{
    public function __construct($repository, $model, array $data, public array $changes = [], string $action = 'updated')
    {
        parent::__construct($repository, $model, $data, $action);
    }
}
