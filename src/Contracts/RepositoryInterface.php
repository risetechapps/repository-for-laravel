<?php

namespace RiseTechApps\Repository\Contracts;
interface RepositoryInterface
{
    public function first();

    public function get();

    public function findById($id);

    public function find($id): static;

    public function setId($id): static;

    public function findWhere(array $conditions);

    public function findWhereCustom(array $conditions);

    public function findWhereJson(string $path, $value);

    public function searchFullText(string $query, array $columns);

    public function whereDate(string $column, string $operator, $value);

    public function whereIn(string $column, array $values);

    public function whereBetween(string $column, array $values);

    public function groupBy(string|array $columns);

    public function fuzzySearch(string $term, string $column);

    public function findWhereEmail($valor);

    public function findWhereFirst($column, $valor);

    public function count();

    public function exists();

    public function latest(string $column = 'created_at');

    public function oldest(string $column = 'created_at');

    public function withCount(string|array $relations);

    public function store(array $data);

    public function storeMany(array $records): array;

    public function upsert(array $records, array $uniqueBy): array;

    public function update($id, array $data);

    public function updateMany(array $data, array $conditions);

    public function createOrUpdate($id, array $data);

    public function firstOrCreate(array $attributes, array $values = []);

    public function updateOrCreate(array $attributes, array $values = []);

    public function duplicate($id, array $modifications = []);

    public function increment($id, string $column, int $amount = 1);

    public function decrement($id, string $column, int $amount = 1);

    public function chunk(int $size, callable $callback);

    public function delete(): bool;

    public function deleteMany(array $conditions): int;

    public function forceDelete(): bool;

    public function forceDeleteMany(array $conditions): int;

    public function restore(): bool;

    public function restoreMany(array $conditions): int;

    public function relationships(...$relationships): static;

    public function select(array $columns = ['*']): static;

    public function scope(string $scopeName, ...$parameters): static;

    public function paginate($totalPage = 10);

    public function when($condition, callable $callback): static;

    public function selectOptimized(array $columns): static;

    public function cursorPaginate(int $perPage);

    public function dataTable();

    public function orderBy($column, $order = 'DESC');

    public function useTrashed(bool $permission): static;

    public function onlyTrashed(): static;

    public function clearCacheForEntity(string $method = '', array $parameters = []): void;

    public function entity();

    public function entityOn();

    public function setTags($tags): static;

    public function withCacheTags(array $tags): static;

    public function cacheIf(callable $condition): static;

    public function limit(int $value): static;

    public function warmCache(array $methods): void;

    public function enableSlowQueryLog(int $threshold): static;

    public function getMetrics(): array;

    public function pluck(string $column, ?string $key = null);

    public function sum(string $column):int|float;

    public function avg(string $column): int|float|null;

    public function min(string $column): mixed;

    public function max(string $column): mixed;

    public function registerViews(): array;

    public function createMaterializedViews(): void;

    public function refreshMaterializedViews(?string $view = null, bool $concurrently = true): void;

    public function useMaterializedView(string $view): static;

    public function cacheFor(int $minutes): static;

    public function cacheForHours(int $hours): static;

    public function cacheForDays(int $days): static;

    /**
     * Cria uma definição de view materializada usando Query Builder.
     *
     * @param string $name Nome da view
     * @param callable $callback Função que recebe Query Builder e retorna a query
     * @return array Configuração da view
     */
    public function view(string $name, callable $callback): array;
}
