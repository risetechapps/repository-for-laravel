<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Events;

class RepositoryUpdated extends RepositoryEvent
{
    /**
     * Dados que foram alterados.
     */
    public array $changes;

    public function __construct($repository, $model, array $data, array $changes = [], string $action = 'updated')
    {
        parent::__construct($repository, $model, $data, $action);
        $this->changes = $changes;
    }
}
