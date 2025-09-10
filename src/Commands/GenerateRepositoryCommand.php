<?php

namespace RiseTechApps\Repository\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

class GenerateRepositoryCommand extends Command
{
    protected $signature = 'make:repository {name}';

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
        $path = app_path('Repositories');
        $pathRepository = app_path('Repositories\\' . $this->argument('name'));

        $this->line("Creating Repository " . $this->argument('name') . "...");

        if (is_dir($pathRepository)) {
            $this->error("Repository " . $this->argument('name') . " - " . $pathRepository . " already exists");
            return;
        }

        $this->setAskModel();

        if (!is_dir($path)) File::makeDirectory($path);
        if (!is_dir($pathRepository)) File::makeDirectory($pathRepository);

        $this->getStubContentsInterface($pathRepository);
        $this->getStubContentsEloquent($pathRepository);


        $this->line("Remember to register " . $this->argument('name') . " at em config repository");

        $this->line("Ex: '" .
            $this->getNamespace() . "\\" . $this->getNameClass("Repository") . "::class' => '" .
            $this->getNamespace() . "\\" . $this->getNameClass("EloquentRepository") . "::class'"
        );

        $this->line("Repository " . $this->argument('name') . " created successfully");
    }

    public function setAskModel(): void
    {
        $model = $this->ask('What is the model namespace ? Ex: \Client\ClientModel');

        if (is_null($model) || empty($model)) {
            $this->error('Need to enter the model namespace');
            $this->setAskModel();
        }

        $this->model = $model;
    }

    public function getStubContentsInterface(string $path): void
    {
        $list = [
            '{{ namespace }}' => $this->getNamespace(),
            '{{ class }}' => $this->getNameClass('Repository'),
        ];

        $contents = file_get_contents($this->getStubPathInterface());

        foreach ($list as $item => $value) {
            $contents = str_replace($item, $value, $contents);
        }

        $this->files->put($path . "\\" . $this->getNameClass('Repository') . ".php", $contents);
    }


    private function getStubContentsEloquent(string $path): void
    {
        $list = [
            '{{ namespace }}' => $this->getNamespace(),
            '{{ class }}' => $this->getNameClass('EloquentRepository'),
            '{{ model }}' => $this->model,
            '{{ contract }}' => $this->model . "Repository",
            '{{ namespaceModel }}' => $this->getNameModel(),
        ];

        $contents = file_get_contents($this->getStubPathEloquent());

        foreach ($list as $item => $value) {
            $contents = str_replace($item, $value, $contents);
        }

        $this->files->put($path . "\\" . $this->getNameClass('EloquentRepository') . ".php", $contents);
    }

    public function getStubPathInterface(): string
    {
        return __DIR__ . '/../stubs/contract.stub';
    }

    public function getStubPathEloquent(): string
    {
        return __DIR__ . '/../stubs/eloquent.stub';
    }

    public function getNamespace(): string
    {
        return "App\\Repositories\\" . $this->model;
    }

    public function getNameClass( string $name):string
    {
        return $this->model . $name;
    }

    public function getNameModel(): string
    {
        return "App\\Models\\" . $this->model;
    }
}
