<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Events;

class RepositoryUpdating extends RepositoryEvent
{
    /**
     * Pode ser usado para cancelar a operação.
     */
    public bool $shouldUpdate = true;

    public function __construct($repository, $model, array $data, public array $changes = [], string $action = 'updating')
    {
        parent::__construct($repository, $model, $data, $action);
    }

    public function cancel(): void
    {
        $this->shouldUpdate = false;
    }
}
