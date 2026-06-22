<?php

use RiseTechApps\Repository\Exception\InvalidFilterException;
use RiseTechApps\Repository\Tests\Fixtures\ProductEloquentRepository;

beforeEach(function () {
    $this->repo = new ProductEloquentRepository();
});

it('rejects an injection attempt in the column name', function () {
    $this->repo->fuzzySearch('x', 'name" OR 1=1 --');
})->throws(InvalidFilterException::class);

it('rejects a column that does not exist in the schema', function () {
    $this->repo->fuzzySearch('x', 'coluna_inexistente');
})->throws(InvalidFilterException::class);

it('rejects an injection attempt in searchFullText columns', function () {
    $this->repo->searchFullText('x', ['name', 'evil") OR 1=1 --']);
})->throws(InvalidFilterException::class);

it('rejects an injection attempt in the findWhereJson base column', function () {
    $this->repo->findWhereJson('meta" OR 1=1 --.key', 'value');
})->throws(InvalidFilterException::class);
