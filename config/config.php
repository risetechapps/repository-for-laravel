<?php

/*
 * Configurações do Repository Package
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Repositories Registrados
    |--------------------------------------------------------------------------
    |
    | Mapeamento de interfaces para implementações.
    | Exemplo: 'App\Repositories\Contracts\UserRepository' => 'App\Repositories\UserEloquentRepository'
    |
    */
    'repositories' => [],

    /*
    |--------------------------------------------------------------------------
    | Eventos
    |--------------------------------------------------------------------------
    |
    | Liste os listeners para cada evento do repository.
    | Os listeners são executados automaticamente quando os eventos são disparados.
    |
    */
    'events' => [
        \RiseTechApps\Repository\Events\RepositoryCreating::class => [],
        \RiseTechApps\Repository\Events\RepositoryCreated::class => [],
        \RiseTechApps\Repository\Events\RepositoryUpdating::class => [],
        \RiseTechApps\Repository\Events\RepositoryUpdated::class => [],
        \RiseTechApps\Repository\Events\RepositoryDeleting::class => [],
        \RiseTechApps\Repository\Events\RepositoryDeleted::class => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Configurações de cache do repository.
    |
    */
    'cache' => [
        /*
        | Tempo padrão de expiração do cache em minutos (null = usar config do repository)
        */
        'default_ttl' => null,

        /*
        | Drivers que não suportam tags
        */
        'unsupported_tag_drivers' => ['file', 'database', 'array'],

        /*
        | Habilitar cache warming automático
        */
        'warming_enabled' => false,

        /*
        | Métodos para cache warming
        */
        'warming_methods' => ['get', 'first', 'findById'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Query Logging
    |--------------------------------------------------------------------------
    |
    | Configurações para log de queries lentas.
    |
    */
    'query_logging' => [
        'enabled' => false,
        'slow_query_threshold' => 100, // em milissegundos
        'log_channel' => 'default',
    ],

    /*
    |--------------------------------------------------------------------------
    | Sanitização
    |--------------------------------------------------------------------------
    |
    | Configurações de sanitização automática de inputs.
    |
    */
    'sanitization' => [
        'enabled' => true,
        'strip_tags' => true,
        'allowed_tags' => [], // tags HTML permitidas (vazio = remove tudo)
    ],
];
