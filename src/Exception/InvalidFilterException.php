<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Exception;

class InvalidFilterException extends RepositoryException
{
    /**
     * Filtro inválido que causou a exceção.
     */
    protected array $invalidFilter;

    /**
     * Operadores permitidos.
     *
     * @var string[]
     */
    protected array $allowedOperators = [
        '=',
        '<>',
        '!=',
        '<',
        '>',
        '<=',
        '>=',
        'LIKE',
        'NOT LIKE',
        'IN',
        'NOT IN',
        'BETWEEN',
        'NOT BETWEEN',
        'IS',
        'IS NOT',
        'NULL',
        'NOT NULL',
    ];

    public function __construct(string $message, array $invalidFilter = [], ?string $operator = null)
    {
        $this->invalidFilter = $invalidFilter;

        if ($operator !== null) {
            $message .= " Operador inválido: [{$operator}].";
        }

        parent::__construct($message, 400, null, [
            'filter' => $invalidFilter,
            'allowed_operators' => $this->allowedOperators,
        ]);
    }

    /**
     * Cria uma exceção para operador não permitido.
     */
    public static function invalidOperator(string $operator, array $filter = []): self
    {
        return new static(
            "Operador [{$operator}] não é permitido.",
            $filter,
            $operator
        );
    }

    /**
     * Cria uma exceção para filtro malformado.
     */
    public static function malformedFilter(array $filter, string $reason = ''): self
    {
        return new static(
            "Filtro malformado. {$reason}",
            $filter
        );
    }

    /**
     * Retorna o filtro inválido.
     */
    public function getInvalidFilter(): array
    {
        return $this->invalidFilter;
    }

    /**
     * Retorna os operadores permitidos.
     */
    public function getAllowedOperators(): array
    {
        return $this->allowedOperators;
    }
}
