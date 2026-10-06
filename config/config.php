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
        | Chave geral do cache. false = o cache de query de TODOS os repositórios
        | e o middleware cacheResponse viram passagem direta (nada é lido, gravado
        | ou invalidado; nenhum job de warming). A API (withoutCache(), cacheFor(),
        | flushTags()...) continua existindo — nenhum package precisa mudar.
        | Por repositório: `protected bool $cacheEnabled = false;`.
        | As views materializadas NÃO dependem disto: continuam sendo refeitas.
        */
        'enabled' => (bool) env('REPOSITORY_CACHE_ENABLED', true),

        /*
        | Tempo padrão de expiração do cache em minutos. Vale para repositórios
        | que NÃO sobrescrevem $defaultCacheTtlMinutes; cacheFor() da chamada
        | sempre prevalece. null = 1440 (24h, comportamento antigo).
        */
        'default_ttl' => env('REPOSITORY_CACHE_TTL', 15),

        /*
        | Store de cache usado pelo repositório (core) e pelo middleware
        | cacheResponse. null = usa o store default da aplicação (cache.default).
        |
        | A invalidação por tag — tanto do cache do core quanto do cacheResponse —
        | EXIGE um store taggable (redis, memcached). Se o default da app for
        | file/database, aponte aqui para um store redis; senão a invalidação vira
        | no-op e o cache só expira por TTL (Furo 2). O ServiceProvider emite um
        | warning no boot quando o store resolvido não suporta tags.
        */
        'store' => env('REPOSITORY_CACHE_STORE', null),

        /*
        | Drivers que não suportam tags.
        |
        | NÃO inclua 'array' aqui: o ArrayStore do Laravel estende TaggableStore
        | e suporta tags normalmente — marcá-lo como sem suporte desliga a
        | invalidação por tag em ambiente de teste.
        */
        'unsupported_tag_drivers' => ['file', 'database', 'dynamodb'],

        /*
        | Habilitar cache warming automático após escritas (re-aquece o cache
        | que o clearCacheForEntity acabou de limpar). Default false (rebuild lazy
        | no próximo read): o RegenerateCacheJob roda no worker, num contexto
        | (usuário/filial) diferente de quem lê — com cache segmentado por
        | contexto ele aquece uma chave que ninguém consulta e ainda executa um
        | get() da tabela inteira a cada escrita.
        */
        'warming_enabled' => (bool) env('REPOSITORY_CACHE_WARMING', false),

        /*
        | Métodos re-aquecidos pelo warming. Aceita: get, first, dataTable.
        | (findById é ignorado aqui — depende de um id, indisponível no contexto.)
        */
        'warming_methods' => ['get', 'first'],
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
