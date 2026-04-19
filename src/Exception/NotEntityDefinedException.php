<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Exception;

class NotEntityDefinedException extends RepositoryException
{
    public function __construct(string $repositoryClass = '')
    {
        $message = $repositoryClass !== ''
            ? "O método entity() não foi definido no repository [{$repositoryClass}]."
            : "O método entity() não foi definido no repository.";

        parent::__construct($message, 500, null, [
            'repository' => $repositoryClass,
        ]);
    }
}
