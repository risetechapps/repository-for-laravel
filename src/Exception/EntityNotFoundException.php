<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Exception;

class EntityNotFoundException extends RepositoryException
{

    public function __construct(protected string $entityName, protected string|int|null $searchedId = null, ?string $message = null)
    {
        $message ??= 'Recurso não encontrado.';

        parent::__construct($message, 404, null, [
            'entity' => $this->entityName,
            'id' => $this->searchedId,
        ]);
    }

    /**
     * Retorna o ID que foi buscado.
     */
    public function getSearchedId(): string|int|null
    {
        return $this->searchedId;
    }

    /**
     * Retorna o nome da entidade.
     */
    public function getEntityName(): string
    {
        return $this->entityName;
    }
}
