<?php

use RiseTechApps\Repository\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

/**
 * Marca o teste como PG-only: em sqlite ele é pulado, no job de Postgres da CI
 * ele roda de verdade.
 */
function requiresPostgres(): void
{
    if (! TestCase::onPostgres()) {
        test()->markTestSkipped('Requer PostgreSQL (rode com DB_CONNECTION=pgsql).');
    }
}
