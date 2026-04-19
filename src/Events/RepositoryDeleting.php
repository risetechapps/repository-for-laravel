<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Events;

class RepositoryDeleting extends RepositoryEvent
{
    /**
     * Pode ser usado para cancelar a operação.
     */
    public bool $shouldDelete = true;

    public function cancel(): void
    {
        $this->shouldDelete = false;
    }
}
