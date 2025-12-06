<?php

namespace RiseTechApps\Repository\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

class GenerateRepositoryCommand extends Command
{
    protected $signature = 'repository:make {name}';

    protected $description = 'Make and interface class repository';

    private ?string $model = null;

    protected $files;


    public function __construct(Filesystem $files)
    {
        parent::__construct();

        $this->files = $files;
    }

    public function handle(): void
    {
        $repositoryName = str_replace('/', '\\', $this->argument('name'));
        $repositoryName = trim($repositoryName, '\\');

        $basePath = app_path('Repositories');
        $pathRepository = $basePath . DIRECTORY_SEPARATOR . str_replace('\\', DIRECTORY_SEPARATOR, $repositoryName);

        $this->line("Creating Repository " . $repositoryName . "...");

        if (is_dir($pathRepository)) {
            $this->error("Repository " . $repositoryName . " - " . $pathRepository . " already exists");
            return;
        }

        $this->setAskModel();

        if (!is_dir($basePath)) File::makeDirectory($basePath);
        if (!is_dir($pathRepository)) File::makeDirectory($pathRepository);

        $this->getStubContentsInterface($pathRepository);
        $this->getStubContentsEloquent($pathRepository);


        $this->line("Remember to register " . $repositoryName . " at em config repository");

        $this->line("Ex: '" .
            $this->getNamespace($repositoryName) . "\\" . $this->getNameClass("Repository") . "::class' => '" .
            $this->getNamespace($repositoryName) . "\\" . $this->getNameClass("EloquentRepository") . "::class'"
        );

        $this->line("Repository " . $repositoryName . " created successfully");
    }

    public function setAskModel(): void
    {
        while (is_null($this->model) || empty($this->model)) {
            $model = $this->ask('What is the model namespace ? Ex: App\\Models\\Client');

            if (is_null($model) || empty($model)) {
                $this->error('Need to enter the model namespace');
            } else {
                $this->model = $model;
            }
        }
    }

    public function getStubContentsInterface(string $path): void
    {
        $repositoryName = str_replace('/', '\\', $this->argument('name'));
        $repositoryName = trim($repositoryName, '\\');

        $list = [
            '{{ namespace }}' => $this->getNamespace($repositoryName),
            '{{ class }}' => $this->getNameClass('Repository'),
        ];

        $contents = file_get_contents($this->getStubPathInterface());

        foreach ($list as $item => $value) {
            $contents = str_replace($item, $value, $contents);
        }

        $this->files->put($path . DIRECTORY_SEPARATOR . $this->getNameClass('Repository') . ".php", $contents);
    }


    private function getStubContentsEloquent(string $path): void
    {
        $repositoryName = str_replace('/', '\\', $this->argument('name'));
        $repositoryName = trim($repositoryName, '\\');

        $list = [
            '{{ namespace }}' => $this->getNamespace($repositoryName),
            '{{ class }}' => $this->getNameClass('EloquentRepository'),
            '{{ model }}' => $this->model,
            '{{ contract }}' => $this->getModelNameOnly() . "Repository",
            '{{ namespaceModel }}' => $this->model,
        ];

        $contents = file_get_contents($this->getStubPathEloquent());

        foreach ($list as $item => $value) {
            $contents = str_replace($item, $value, $contents);
        }

        $this->files->put($path . DIRECTORY_SEPARATOR . $this->getNameClass('EloquentRepository') . ".php", $contents);
    }

    public function getStubPathInterface(): string
    {
        return __DIR__ . '/../stubs/contract.stub';
    }

    public function getStubPathEloquent(): string
    {
        return __DIR__ . '/../stubs/eloquent.stub';
    }

    public function getNamespace(string $repositoryName): string
    {
        return "App\\Repositories\\" . $repositoryName;
    }

    private function getModelNameOnly(): string
    {
        $parts = explode('\\', $this->model);
        return end($parts);
    }

    public function getNameClass( string $name):string
    {
        return $this->getModelNameOnly() . $name;
    }
}
