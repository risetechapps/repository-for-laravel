<?php

use Illuminate\Support\Facades\Event;
use RiseTechApps\Repository\Events\RepositoryUpdating;
use RiseTechApps\Repository\Tests\Fixtures\Gender;
use RiseTechApps\Repository\Tests\Fixtures\ProductEloquentRepository;

beforeEach(function () {
    $this->repo = new ProductEloquentRepository();
});

/** Captura o array $changes do evento RepositoryUpdating. */
function capturarChanges(): object
{
    $spy = new class {
        public ?array $changes = null;
    };

    Event::listen(RepositoryUpdating::class, function (RepositoryUpdating $event) use ($spy) {
        $spy->changes = $event->changes;
    });

    return $spy;
}

it('atualiza um model com atributo castado para enum sem lancar Error', function () {
    $p = $this->repo->store(['name' => 'A', 'gender' => Gender::Male]);

    $this->repo->update($p->id, ['name' => 'B']);

    $fresh = $this->repo->withoutCache()->findById($p->id);

    expect($fresh->name)->toBe('B')
        ->and($fresh->gender)->toBe(Gender::Male);
});

it('detecta mudanca de enum quando o valor realmente muda', function () {
    $p = $this->repo->store(['name' => 'A', 'gender' => Gender::Male]);
    $spy = capturarChanges();

    $this->repo->update($p->id, ['gender' => Gender::Female]);

    expect($spy->changes)->toHaveKey('gender')
        ->and($this->repo->withoutCache()->findById($p->id)->gender)->toBe(Gender::Female);
});

it('nao marca mudanca quando o enum equivale ao valor atual', function () {
    $p = $this->repo->store(['name' => 'A', 'gender' => Gender::Male]);
    $spy = capturarChanges();

    // Enum igual e string crua equivalente nao contam como alteracao.
    $this->repo->update($p->id, ['gender' => Gender::Male, 'name' => 'A']);

    expect($spy->changes)->toBe([]);
});

it('compara valor enum atual com string crua vinda do request', function () {
    $p = $this->repo->store(['name' => 'A', 'gender' => Gender::Male]);
    $spy = capturarChanges();

    $this->repo->update($p->id, ['gender' => 'female']);

    expect($spy->changes)->toHaveKey('gender')
        ->and($this->repo->withoutCache()->findById($p->id)->gender)->toBe(Gender::Female);
});

it('atualiza model com atributo castado para array sem lancar Error', function () {
    $p = $this->repo->store(['name' => 'A', 'meta' => ['cor' => 'azul']]);
    $spy = capturarChanges();

    $this->repo->update($p->id, ['meta' => ['cor' => 'verde']]);

    expect($spy->changes)->toHaveKey('meta')
        ->and($this->repo->withoutCache()->findById($p->id)->meta)->toBe(['cor' => 'verde']);
});

it('trata null e string vazia como equivalentes (compatibilidade)', function () {
    $p = $this->repo->store(['name' => 'A', 'description' => null]);
    $spy = capturarChanges();

    $this->repo->update($p->id, ['description' => '']);

    expect($spy->changes)->toBe([]);
});

it('detecta mudanca de int para string equivalente como nao-mudanca', function () {
    $p = $this->repo->store(['name' => 'A', 'stock' => 5]);
    $spy = capturarChanges();

    $this->repo->update($p->id, ['stock' => '5']);

    expect($spy->changes)->toBe([]);
});
