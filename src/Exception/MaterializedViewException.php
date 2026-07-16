<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Exception;

class MaterializedViewException extends RepositoryException
{
    public function __construct(
        string $message,
        protected string $operation = '',
        protected ?string $viewName = null,
         ?\Throwable $previous = null
    ) {
        $this->operation = $operation;
        $this->viewName = $viewName;

        if ($this->viewName !== null) {
            $message = "[View: {$this->viewName}] {$message}";
        }

        parent::__construct($message, 0, $previous, [
            'view' => $this->viewName,
            'operation' => $this->operation,
        ]);
    }

    /**
     * Cria uma exceção para view não encontrada.
     */
    public static function viewNotFound(string $viewName): self
    {
        return new static(
            "View materializada não encontrada ou não registrada.",
            'use',
            $viewName
        );
    }

    /**
     * Cria uma exceção para erro ao criar view.
     */
    public static function creationFailed(string $viewName, string $sql, ?\Throwable $previous = null): self
    {
        return new static(
            "Falha ao criar view materializada. SQL: {$sql}",
            'create',
            $viewName,
            $previous
        );
    }

    /**
     * Cria uma exceção para erro ao atualizar view.
     */
    public static function refreshFailed(string $viewName, ?\Throwable $previous = null): self
    {
        return new static(
            "Falha ao atualizar view materializada.",
            'refresh',
            $viewName,
            $previous
        );
    }

    /**
     * Cria uma exceção para uso indevido de view com soft deletes.
     */
    public static function invalidUsageWithSoftDeletes(string $viewName): self
    {
        return new static(
            "View materializada não pode ser usada com onlyTrashed() ou useTrashed().",
            'invalid_usage',
            $viewName
        );
    }

    /**
     * Retorna o nome da view.
     */
    public function getViewName(): ?string
    {
        return $this->viewName;
    }

    /**
     * Retorna a operação.
     */
    public function getOperation(): string
    {
        return $this->operation;
    }
}
