<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Events;

class RepositoryCreating extends RepositoryEvent
{
    /**
     * Pode ser usado para cancelar a operação retornando false.
     */
    public bool $shouldCreate = true;

    public function cancel(): void
    {
        $this->shouldCreate = false;
    }
}
