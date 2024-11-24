<?php

namespace RiseTechApps\Repository\Contracts;
interface RepositoryInterface
{
    public function first();

    public function get();

    public function findById($id);

    public function find($id): static;

    public function findWhere($column, $valor);

    public function findWhereEmail($valor);

    public function findWhereFirst($column, $valor);

    public function store(array $data);

    public function update($id, array $data);

    public function createOrUpdate($id, array $data);

    public function delete(): bool;

    public function forceDelete(): bool;

    public function restore(): bool;

    public function relationships(...$relationships): static;

    public function paginate($totalPage = 10);

    public function dataTable();

    public function orderBy($column, $order = 'DESC');

    public function useTrashed(string $permission): static;
}
