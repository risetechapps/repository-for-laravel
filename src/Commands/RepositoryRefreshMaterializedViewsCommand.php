<?php

namespace RiseTechApps\Repository\Commands;

use Illuminate\Console\Command;
use RiseTechApps\Repository\Repository;

class RepositoryRefreshMaterializedViewsCommand extends Command
{
    protected $signature = 'repository:refresh-materialized-views';

    protected $description = 'Updates the Materialized Views for one or all registered repositories.';

    public function handle(): void
    {
        $repositoryContracts = Repository::getBindingsRepository();

        if (empty($repositoryContracts)) {
            $this->error('No Repository with Materialized Views found.');
            return;
        }

        $choices = array_merge(['All'], $repositoryContracts);

        $selectedRepository = $this->choice(
            'Which repository would you like to update?',
            $choices,
            0
        );

        $repositoriesToProcess = [];

        if ($selectedRepository === 'All') {
            $repositoriesToProcess = $repositoryContracts;
            $this->info("Starting the update of All repositories...");
        } else {
            // Se um único repositório foi escolhido
            $repositoriesToProcess[] = $selectedRepository;
            $this->info("Starting the repository update: {$selectedRepository}");
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
                $repositoryInstance->refreshMaterializedViews();

                $this->info('    [OK] Updated successfully.');

            } catch (\Throwable $e) {
                // Captura exceções, mas é bom logar ou exibir a mensagem
                $this->error("    [ERROR] Failed to update: " . $e->getMessage());
            }
            $bar->advance();
        }

        $bar->finish();
        $this->line("\n\nUpdate process complete!");
    }
}
