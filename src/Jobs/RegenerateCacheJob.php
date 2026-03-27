<?php

namespace RiseTechApps\Repository\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RiseTechApps\Repository\Core\BaseRepository;
use RiseTechApps\Repository\Repository;

class RegenerateCacheJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected BaseRepository $repository;
    protected array $method = [];
    protected array $parameters;

    public function __construct(BaseRepository $repository, array $method, array $parameters = [])
    {
        $this->repository = $repository;
        $this->method = $method;
        $this->parameters = $parameters;
    }

    public function handle(): void
    {
        foreach ($this->method as $method) {
            $this->repository->rememberCache(function () use ($method) {
                switch ($method) {
                    case Repository::$methodFind:
                        return $this->repository->find($this->parameters[0] ?? null);
                    case Repository::$methodAll:
                        return $this->repository->get();
                }
            }, $method, $this->parameters);
        }
    }
}
