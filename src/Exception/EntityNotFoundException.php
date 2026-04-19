<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Exception;

class EntityNotFoundException extends RepositoryException
{
    /**
     * ID que foi buscado.
     */
    protected string|int|null $searchedId;

    /**
     * Nome da entidade.
     */
    protected string $entityName;

    public function __construct(string $entityName, string|int|null $id = null, ?string $message = null)
    {
        $this->entityName = $entityName;
        $this->searchedId = $id;

        $message ??= $id !== null
            ? "Entidade [{$entityName}] com ID [{$id}] não encontrada."
            : "Entidade [{$entityName}] não encontrada.";

        parent::__construct($message, 404, null, [
            'entity' => $entityName,
            'id' => $id,
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
