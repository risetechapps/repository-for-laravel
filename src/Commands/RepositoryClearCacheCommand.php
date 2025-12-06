<?php

namespace RiseTechApps\Repository\Commands;

use Illuminate\Console\Command;
use RiseTechApps\Repository\Repository;

class RepositoryClearCacheCommand extends Command
{
    protected $signature = 'repository:clear-cache';

    protected $description = 'Command to clear the repository cache.';


    public function handle(): void
    {
        //        clearCacheForEntity
        $repositoryContracts = Repository::getBindingsRepository();

        if (empty($repositoryContracts)) {
            $this->error('No Repository found.');
            return;
        }

        $choices = array_merge(['All'], $repositoryContracts);

        $selectedRepository = $this->choice(
            'Which repository would you like to clear the cache from?',
            $choices,
            0
        );

        $repositoriesToProcess = [];

        if ($selectedRepository === 'All') {
            $repositoriesToProcess = $repositoryContracts;
            $this->info("Starting cache clearing for all repositories...");
        } else {
            // Se um único repositório foi escolhido
            $repositoriesToProcess[] = $selectedRepository;
            $this->info("Starting to clear the repository cache: {$selectedRepository}");
        }

        $this->processRepositories($repositoriesToProcess);
    }

    protected function processRepositories(array $repositories): void
    {
        $bar = $this->output->createProgressBar(count($repositories));
        $bar->start();

        foreach ($repositories as $repositoryContract) {
            $this->line("\n  -> Processing: {$repositoryContract}");

            try {
                $repositoryInstance = app($repositoryContract);
                $repositoryInstance->clearCacheForEntity();

                $this->info('    [OK] Successfully deleted.');

            } catch (\Throwable $e) {
                // Captura exceções, mas é bom logar ou exibir a mensagem
                $this->error("    [ERROR] Error when deleting: " . $e->getMessage());
            }

            $bar->advance();
        }

        $bar->finish();
        $this->line("\n\nCleaning process completed!");
    }
}
