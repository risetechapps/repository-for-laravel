<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RiseTechApps\Repository\Core\BaseRepository;

/**
 * Cria índices GIN pg_trgm para as colunas declaradas em $searchableColumns
 * dos repositórios, deixando o ILIKE '%x%' do paginate() usar índice em vez
 * de varrer a tabela inteira.
 *
 * Sem argumento, percorre todos os repositórios de config('repository.repositories').
 * Sem --apply, apenas imprime o SQL (dry-run). Use --concurrently em produção
 * para não travar a tabela durante a criação do índice.
 */
class RepositorySearchIndexesCommand extends Command
{
    protected $signature = 'repository:search-indexes
        {repository? : Class (or binding) of a specific repository}
        {--apply : Runs SQL. Without this flag, it just shows (dry-run)}
        {--concurrently : Create with CREATE INDEX CONCURRENTLY (without write lock)}';

    protected $description = 'Creates GIN indexes pg_trgm for the search ($searchableColumns) columns of repositories';

    public function handle(): int
    {
        $repositories = $this->resolveRepositoryClasses();

        if (empty($repositories)) {
            $this->warn('No repository to process.');
            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $concurrently = (bool) $this->option('concurrently');
        $extensionsDone = [];
        $total = 0;

        foreach ($repositories as $repositoryClass) {
            $repository = $this->makeRepository($repositoryClass);

            if (!$repository instanceof BaseRepository) {
                $this->warn("Ignored [{$repositoryClass}]: Not a BaseRepository.");
                continue;
            }

            $columns = $repository->declaredSearchableColumns();

            if (empty($columns)) {
                $this->line("  <fg=gray>—</> [{$repositoryClass}] No \$searchableColumns declared, jumping.");
                continue;
            }

            $connectionName = $repository->getConnectionName();
            $connection = DB::connection($connectionName);

            if ($connection->getDriverName() !== 'pgsql') {
                $this->warn("Ignored [{$repositoryClass}]: pg_trgm only applies to PostgreSQL (driver: {$connection->getDriverName()}).");
                continue;
            }

            $table = $repository->getTable();
            $this->info("• {$repositoryClass}  →  {$table} ({$connectionName})");

            // pg_trgm precisa existir uma vez por conexão.
            if (!isset($extensionsDone[$connectionName])) {
                $this->ensureExtension($connection, $apply);
                $extensionsDone[$connectionName] = true;
            }

            foreach ($columns as $field) {
                $sql = $this->buildIndexSql($table, (string) $field, $concurrently);
                $total++;

                if (!$apply) {
                    $this->line("    <fg=yellow>[dry-run]</> {$sql};");
                    continue;
                }

                try {
                    $connection->statement($sql);
                    $this->line("    <fg=green>✓</> {$field}");
                } catch (\Throwable $e) {
                    $this->error("    ✗ {$field}: " . $e->getMessage());
                }
            }
        }

        if (!$apply) {
            $this->newLine();
            $this->comment("Dry-run: {$total} index(es) predicted. Run --apply (and --concurrently in production) to execute.");
        }

        return self::SUCCESS;
    }

    /**
     * Resolve a lista de classes de repositório a processar.
     */
    private function resolveRepositoryClasses(): array
    {
        $arg = $this->argument('repository');

        if ($arg) {
            if (!str_contains($arg, '\\')) {
                $arg = "App\\Repositories\\{$arg}";
            }
            return [$arg];
        }

        // config('repository.repositories') mapeia binding => concreto.
        // As chaves (binding/interface) resolvem para o concreto via container.
        return array_keys(config('repository.repositories', []));
    }

    private function makeRepository(string $repositoryClass): ?object
    {
        try {
            return app($repositoryClass);
        } catch (\Throwable $e) {
            $this->error("Unable to instantiate [{$repositoryClass}]:" . $e->getMessage());
            return null;
        }
    }

    private function ensureExtension($connection, bool $apply): void
    {
        if (!$apply) {
            $this->line('    <fg=yellow>[dry-run]</> CREATE EXTENSION IF NOT EXISTS pg_trgm;');
            return;
        }

        try {
            $connection->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (\Throwable $e) {
            $this->warn('    Failed to secure pg_trgm extension (needs privilege): ' . $e->getMessage());
        }
    }

    /**
     * Monta o SQL do índice GIN trigram para uma coluna simples ou path JSON.
     *
     * 'nome'      → USING gin ("nome" gin_trgm_ops)
     * 'dados.cpf' → USING gin (("dados"->>'cpf') gin_trgm_ops)
     */
    private function buildIndexSql(string $table, string $field, bool $concurrently): string
    {
        if (str_contains($field, '.')) {
            [$col, $path] = explode('.', $field, 2);
            $col = str_replace('"', '', $col);
            $path = str_replace("'", "''", $path);
            $expr = "(\"{$col}\"->>'{$path}')";
        } else {
            $col = str_replace('"', '', $field);
            $expr = "\"{$col}\"";
        }

        $name = $this->indexName($table, $field);
        $create = $concurrently ? 'CREATE INDEX CONCURRENTLY IF NOT EXISTS' : 'CREATE INDEX IF NOT EXISTS';

        return "{$create} \"{$name}\" ON \"{$table}\" USING gin ({$expr} gin_trgm_ops)";
    }

    /**
     * Nome determinístico do índice, dentro do limite de 63 chars do Postgres.
     */
    private function indexName(string $table, string $field): string
    {
        $readable = 'gin_trgm_' . $table . '_' . str_replace(['.', '->>', "'", '"'], '_', $field);

        return strlen($readable) <= 63
            ? $readable
            : 'gin_trgm_' . md5($table . '|' . $field);
    }
}
