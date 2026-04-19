<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Exception;

use Exception;

class RepositoryException extends Exception
{
    /**
     * Contexto adicional para debug.
     */
    protected array $context = [];

    public function __construct(string $message = '', int $code = 0, ?Exception $previous = null, array $context = [])
    {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Retorna o contexto adicional da exceção.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Cria uma exceção com contexto de entidade.
     */
    public static function forEntity(string $entity, string $message, array $extraContext = []): self
    {
        return new static(
            "[{$entity}] {$message}",
            0,
            null,
            array_merge(['entity' => $entity], $extraContext)
        );
    }
}
