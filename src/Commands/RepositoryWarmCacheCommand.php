<?php

declare(strict_types=1);

namespace RiseTechApps\Repository\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;

class RepositoryWarmCacheCommand extends Command
{
    protected $signature = 'repository:warm-cache {repository} {--methods=get,first,findById}';

    protected $description = 'Pré-aquece o cache de um repository específico';

    public function handle(): int
    {
        $repositoryClass = $this->argument('repository');
        $methods = explode(',', $this->option('methods'));

        // Se não tiver namespace completo, assume App\Repositories\
        if (!str_contains($repositoryClass, '\\')) {
            $repositoryClass = "App\\Repositories\\{$repositoryClass}";
        }

        if (!class_exists($repositoryClass)) {
            $this->error("Repository [{$repositoryClass}] não encontrado.");
            return self::FAILURE;
        }

        $repository = app($repositoryClass);

        $this->info("Aquecendo cache para [{$repositoryClass}]...");

        foreach ($methods as $method) {
            $this->info("  - Executando: {$method}()");
            match ($method) {
                'get' => $repository->get(),
                'first' => $repository->first(),
                'findById' => $repository->findById(1),
                'dataTable' => $repository->dataTable(),
                default => $this->warn("    Método [{$method}] ignorado (não suportado)"),
            };
        }

        $this->info('Cache aquecido com sucesso!');

        return self::SUCCESS;
    }
}
