<?php

use RiseTechApps\Repository\Tests\Fixtures\ProductEloquentRepository;

/**
 * Interpolação de bindings no SQL da view materializada (substituteBindings).
 * Estes testes olham só a string gerada, então rodam em qualquer driver.
 */
beforeEach(function () {
    $this->repo = new ProductEloquentRepository();
});

function viewSql(callable $callback): string
{
    return (new ProductEloquentRepository())->view('vw_test', $callback)['sql'];
}

it('does not interpret the binding value as a regex replacement', function () {
    // preg_replace tratava "$1" como backreference e o comia silenciosamente.
    $sql = viewSql(fn($q) => $q->select(['id'])->where('name', 'R$1.000'));

    expect($sql)->toContain('R$1.000');
});

it('does not treat a question mark inside a value as the next placeholder', function () {
    $sql = viewSql(fn($q) => $q->select(['id'])
        ->where('name', 'e ai? beleza')
        ->where('status', 'active'));

    // O segundo binding tem que ter ido para o segundo placeholder — e não
    // para o "?" que veio dentro do primeiro valor.
    expect($sql)->toContain('e ai? beleza')
        ->and($sql)->toContain('active')
        ->and(substr_count($sql, "'e ai? beleza'"))->toBe(1);
});

it('escapes a single quote the way the driver expects', function () {
    $sql = viewSql(fn($q) => $q->select(['id'])->where('name', "O'Brien"));

    // addslashes gerava \' — que no PG (standard_conforming_strings on) deixa a
    // aspa fechar a string. O escape correto dobra a aspa.
    expect($sql)->toContain("'O''Brien'")
        ->and($sql)->not->toContain("\\'");
});

it('keeps numeric strings quoted', function () {
    // is_numeric('1e3') é true: a string saía sem aspas e virava número.
    $sql = viewSql(fn($q) => $q->select(['id'])->where('name', '1e3'));

    expect($sql)->toContain("'1e3'");
});

it('renders integers unquoted', function () {
    $sql = viewSql(fn($q) => $q->select(['id'])->where('stock', '>', 10));

    expect($sql)->toContain('> 10')
        ->and($sql)->not->toContain("'10'");
});

it('leaves no placeholder behind', function () {
    $sql = viewSql(fn($q) => $q->select(['id'])
        ->where('name', 'a?b')
        ->where('status', 'active')
        ->where('stock', '>', 3));

    expect(substr_count($sql, '?'))->toBe(1); // só o que veio dentro de 'a?b'
});
