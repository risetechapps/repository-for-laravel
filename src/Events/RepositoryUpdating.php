<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Events;

class RepositoryUpdating extends RepositoryEvent
{
    /**
     * Dados que serão atualizados.
     */
    public array $changes;

    /**
     * Pode ser usado para cancelar a operação.
     */
    public bool $shouldUpdate = true;

    public function __construct($repository, $model, array $data, array $changes = [], string $action = 'updating')
    {
        parent::__construct($repository, $model, $data, $action);
        $this->changes = $changes;
    }

    public function cancel(): void
    {
        $this->shouldUpdate = false;
    }
}
