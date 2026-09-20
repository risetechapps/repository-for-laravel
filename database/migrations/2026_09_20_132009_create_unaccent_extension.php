<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Habilita a extensão unaccent do PostgreSQL e cria um wrapper IMMUTABLE.
 *
 * unaccent() nativa NÃO é marcada IMMUTABLE (depende do dicionário de busca
 * textual configurado em runtime), então o Postgres recusa usá-la direto em
 * índice de expressão. immutable_unaccent() resolve isso fixando o
 * dicionário 'unaccent' explicitamente, tornando o resultado determinístico
 * para um mesmo input — e portanto elegível para indexação.
 *
 * Uso combinado com pg_trgm (ver RepositorySearchIndexesCommand):
 *   CREATE INDEX ... USING gin (immutable_unaccent(coluna) gin_trgm_ops);
 *
 * Nota multi-tenant: CREATE EXTENSION e CREATE FUNCTION são por DATABASE,
 * não se propagam entre bancos diferentes na mesma instância Postgres. Se
 * cada tenant tiver banco próprio (não apenas schema), esta migration
 * precisa rodar em cada banco de tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE EXTENSION IF NOT EXISTS unaccent');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION immutable_unaccent(text)
            RETURNS text AS $$
                SELECT unaccent('unaccent', $1)
            $$ LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP FUNCTION IF EXISTS immutable_unaccent(text)');
        DB::statement('DROP EXTENSION IF EXISTS unaccent');
    }
};
