<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Exception;

class CacheOperationException extends RepositoryException
{

    public function __construct(string $message,protected string $operation = '',protected array $tags = [], ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous, [
            'operation' => $this->operation,
            'tags' => $this->tags,
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
