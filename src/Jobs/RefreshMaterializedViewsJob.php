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

    protected BaseRepository $repository;
    protected array $parameters = [];

    public function __construct(BaseRepository $repository, array $parameters = [])
    {
        $this->repository = $repository;
        $this->parameters = $parameters;
    }

    public function handle(): void
    {
        if(array_key_exists('auth', $this->parameters) && $this->parameters['auth'] != null) {
            auth()->setUser($this->parameters['auth']);
        }

        $this->repository->refreshMaterializedViews();

    }
}
