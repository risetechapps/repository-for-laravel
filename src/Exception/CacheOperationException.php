<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Exception;

class CacheOperationException extends RepositoryException
{
    /**
     * Operação que estava sendo executada.
     */
    protected string $operation;

    /**
     * Tags envolvidas na operação.
     */
    protected array $tags;

    public function __construct(string $message, string $operation = '', array $tags = [], ?\Throwable $previous = null)
    {
        $this->operation = $operation;
        $this->tags = $tags;

        parent::__construct($message, 0, $previous, [
            'operation' => $operation,
            'tags' => $tags,
        ]);
    }

    /**
     * Cria uma exceção para erro ao limpar cache.
     */
    public static function flushFailed(array $tags, ?\Throwable $previous = null): self
    {
        return new static(
            'Falha ao limpar cache das tags: ' . implode(', ', $tags),
            'flush',
            $tags,
            $previous
        );
    }

    /**
     * Cria uma exceção para erro ao armazenar em cache.
     */
    public static function storeFailed(string $key, array $tags, ?\Throwable $previous = null): self
    {
        return new static(
            "Falha ao armazenar cache para a chave [{$key}].",
            'store',
            $tags,
            $previous
        );
    }

    /**
     * Cria uma exceção para driver não suportado.
     */
    public static function unsupportedDriver(string $driver, string $operation): self
    {
        return new static(
            "Driver de cache [{$driver}] não suporta a operação [{$operation}].",
            $operation,
            []
        );
    }

    /**
     * Retorna a operação.
     */
    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * Retorna as tags.
     */
    public function getTags(): array
    {
        return $this->tags;
    }
}
