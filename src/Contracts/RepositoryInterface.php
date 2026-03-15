<?php

namespace RiseTechApps\Repository\Contracts;
interface RepositoryInterface
{
    public function first();

    public function get();

    public function findById($id);

    public function find($id): static;

    public function findWhere(array $conditions);

    public function findWhereCustom(array $conditions);

    public function findWhereEmail($valor);

    public function findWhereFirst($column, $valor);

    public function count();

    public function exists();

    public function latest(string $column = 'created_at');

    public function oldest(string $column = 'created_at');

    public function withCount(string|array $relations);

    public function store(array $data);

    public function storeMany(array $records, bool $useEloquent = false);

    public function update($id, array $data);

    public function updateMany(array $data, array $conditions);

    public function createOrUpdate($id, array $data);

    public function chunk(int $size, callable $callback);

    public function delete(): bool;

    public function forceDelete(): bool;

    public function restore(): bool;

    public function relationships(...$relationships): static;

    public function select(array $columns = ['*']): static;

    public function paginate($totalPage = 10);

    public function dataTable();

    public function orderBy($column, $order = 'DESC');

    public function useTrashed(bool $permission): static;

    public function onlyTrashed(): static;

    public function clearCacheForEntity(string $method = '', array $parameters = []): void;

    public function entity();

    public function entityOn();

    public function setTags($tags): static;

    public function limit(int $value): static;

    public function pluck(string $column, ?string $key = null);

    public function sum(string $column):int|float;

    public function avg(string $column): int|float|null;

    public function min(string $column): mixed;

    public function max(string $column): mixed;

    public function registerViews(): array;

    public function createMaterializedViews(): void;

    public function refreshMaterializedViews(?string $view = null, bool $concurrently = true): void;

    public function useMaterializedView(string $view): static;
}
