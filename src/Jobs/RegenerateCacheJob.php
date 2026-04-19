<?php

declare(strict_types=1);

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

    /**
     * Classe do repository (string) em vez do objeto.
     * Evita serialização pesada e garante estado fresh no handle().
     */
    protected string $repositoryClass;
    protected array $method = [];
    protected array $parameters;

    public function __construct(BaseRepository $repository, array $method, array $parameters = [])
    {
        $this->repositoryClass = get_class($repository);
        $this->method = $method;
        $this->parameters = $parameters;
    }

    public function handle(): void
    {
        /** @var BaseRepository $repository */
        $repository = app($this->repositoryClass);

        foreach ($this->method as $method) {
            $repository->rememberCache(function () use ($repository, $method) {
                switch ($method) {
                    case Repository::$methodFind:
                        return $repository->findById($this->parameters[0] ?? null);
                    case Repository::$methodAll:
                        return $repository->get();
                }
            }, $method, $this->parameters);
        }
    }
}
