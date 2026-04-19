<?php

namespace RiseTechApps\Repository\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RiseTechApps\Repository\Core\BaseRepository;

class RefreshMaterializedViewsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $backoff = 60;
    public int $timeout = 300;

    /**
     * Classe do repository (string) em vez do objeto.
     * Evita serialização pesada e garante estado fresh no handle().
     */
    protected string $repositoryClass;
    protected array $parameters = [];

    public function __construct(BaseRepository $repository, array $parameters = [])
    {
        $this->repositoryClass = get_class($repository);
        $this->parameters = $parameters;
    }

    public function handle(): void
    {
        /** @var BaseRepository $repository */
        $repository = app($this->repositoryClass);

        $previousUser = auth()->check() ? auth()->user() : null;

        try {
            if (array_key_exists('auth', $this->parameters) && $this->parameters['auth'] != null) {
                auth()->setUser($this->parameters['auth']);
            }

            $repository->refreshMaterializedViews();
        } finally {
            if ($previousUser !== null) {
                auth()->setUser($previousUser);
            } else {
                auth()->logout();
            }
        }
    }
}
