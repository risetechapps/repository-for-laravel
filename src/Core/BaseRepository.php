<?php

namespace RiseTechApps\Repository\Core;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RiseTechApps\Repository\Contracts\RepositoryInterface;
use RiseTechApps\Repository\Events\AfterRefreshAllMaterializedViewsJobEvent;
use RiseTechApps\Repository\Events\AfterRefreshMaterializedViewsJobEvent;
use RiseTechApps\Repository\Events\BeforeRefreshAllMaterializedViewsJobEvent;
use RiseTechApps\Repository\Events\BeforeRefreshMaterializedViewsJobEvent;
use RiseTechApps\Repository\Events\RepositoryCreated;
use RiseTechApps\Repository\Events\RepositoryCreating;
use RiseTechApps\Repository\Events\RepositoryDeleted;
use RiseTechApps\Repository\Events\RepositoryDeleting;
use RiseTechApps\Repository\Events\RepositoryUpdated;
use RiseTechApps\Repository\Events\RepositoryUpdating;
use RiseTechApps\Repository\Exception\CacheOperationException;
use RiseTechApps\Repository\Exception\EntityNotFoundException;
use RiseTechApps\Repository\Exception\InvalidFilterException;
use RiseTechApps\Repository\Exception\MaterializedViewException;
use RiseTechApps\Repository\Exception\NotEntityDefinedException;
use RiseTechApps\Repository\Jobs\RefreshMaterializedViewsJob;
use RiseTechApps\Repository\Jobs\RegenerateCacheJob;
use RiseTechApps\Repository\Repository;

/**
 * @template TModel of \Illuminate\Database\Eloquent\Model
 *
 * Repositórios filhos podem fixar o tipo do model para ganhar autocomplete e
 * análise estática precisa nos retornos:
 *
 *   /** @extends BaseRepository<\App\Models\Client> *\/
 *   class ClientEloquentRepository extends BaseRepository { ... }
 */
abstract class BaseRepository implements RepositoryInterface
{
    protected ?string $activeView = null;
    protected string $entityClass;
    /** @var \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder|null */
    protected $currentBuilder = null;
    protected Carbon $tll;
    protected $driver;
    protected bool $supportTag      = false;
    protected bool $permission      = false;
    protected $relationships        = [];
    protected string|int $id;
    protected bool $hasContainsSoftDelete = false;
    protected array $tags           = [];

    /**
     * Flag para filtrar somente registros soft-deleted.
     * Ativada via onlyTrashed(). Mutuamente exclusiva com useTrashed().
     */
    protected bool $onlyTrashedMode = false;

    /**
     * Colunas permitidas para ordenação no paginate().
     * Subclasses devem sobrescrever para liberar colunas.
     * Se vazio, usa 'id' como fallback seguro (evita SQL Injection).
     */
    protected array $allowedSortColumns = [];

    /**
     * Colunas permitidas em buscas com SQL raw (fuzzySearch, searchFullText,
     * findWhereJson). Se vazio, valida contra as colunas reais da tabela.
     * Subclasses podem sobrescrever para restringir ainda mais.
     */
    protected array $allowedColumns = [];

    /**
     * Operadores permitidos em findWhereCustom.
     * Subclasses podem sobrescrever para adicionar/remover operadores.
     */
    protected array $allowedOperators = [
        '=',
        '<>',
        '!=',
        '<',
        '>',
        '<=',
        '>=',
        'LIKE',
        'NOT LIKE',
        'IN',
        'NOT IN',
        'BETWEEN',
        'NOT BETWEEN',
        'IS',
        'IS NOT',
    ];

    /**
     * Quando true, a próxima operação terminal ignora o cache e vai direto ao banco.
     * Resetado automaticamente pelo resetScope() após cada operação.
     */
    protected bool $bypassCache = false;

    /**
     * Callback para condição de cache.
     * Se definido, só cacheia se o callback retornar true.
     * Resetado automaticamente pelo resetScope() após cada operação.
     */
    protected $cacheCondition = null;

    /**
     * Threshold para slow query log (em ms).
     * 0 = desabilitado.
     * Resetado automaticamente pelo resetScope() após cada operação.
     */
    protected int $slowQueryThreshold = 0;

    /**
     * Métricas de uso do repository.
     * @var array
     */
    protected static array $metrics = [
        'total_queries' => 0,
        'total_cache_hits' => 0,
        'total_cache_misses' => 0,
        'slow_queries' => [],
        'avg_query_time' => 0,
    ];

    /**
     * Limite de registros a retornar. null = sem limite.
     * Resetado automaticamente pelo resetScope() após cada operação.
     */
    protected ?int $limitValue = null;

    /**
     * TTL padrão do cache em minutos.
     * Subclasses podem sobrescrever para definir um padrão diferente.
     */
    protected int $defaultCacheTtlMinutes = 1440; // 24 horas

    /**
     * TTL customizado para a próxima operação (em minutos).
     * null = usar defaultCacheTtlMinutes.
     * Resetado automaticamente pelo resetScope() após cada operação.
     */
    protected ?int $customCacheTtlMinutes = null;

    public function __construct()
    {
        $this->entityClass           = $this->entity();
        $this->hasContainsSoftDelete = $this->containsSoftDelete();
        $this->tll                  = Carbon::now()->addMinutes($this->defaultCacheTtlMinutes);
        $this->supportTag           = $this->supportsTags();
    }

    // =========================================================================
    // BOOT / RESOLUÇÃO
    // =========================================================================

    /**
     * @throws NotEntityDefinedException
     */
    protected function resolveEntity(): mixed
    {
        if (!method_exists($this, 'entity')) {
            throw new NotEntityDefinedException;
        }
        return app($this->entity());
    }

    protected function containsSoftDelete(): bool
    {
        return collect(class_uses_recursive($this->entity()))->contains(SoftDeletes::class);
    }


    // =========================================================================
    // RESET DE ESCOPO — evita state leak entre chamadas no mesmo ciclo de vida
    // =========================================================================

    /**
     * Reseta todos os escopos voláteis após cada operação terminal.
     * Garante que onlyTrashed, useTrashed, relationships, tags e activeView
     * não vazem para a próxima chamada quando o repositório é reutilizado.
     */
    protected function resetScope(): void
    {
        $this->onlyTrashedMode = false;
        $this->permission      = false;
        $this->relationships   = [];
        $this->tags            = [];
        $this->activeView      = null;
        $this->bypassCache     = false;
        $this->cacheCondition   = null;
        $this->slowQueryThreshold = 0;
        $this->limitValue      = null;
        $this->customCacheTtlMinutes = null;
        $this->entityClass     = $this->entity();
        $this->currentBuilder  = null;
    }

    /**
     * Gera uma query limpa a partir do model. Os global scopes do model
     * (se houver) são aplicados aqui pelo Eloquent.
     */
    protected function newQuery()
    {
        // Se já iniciamos um builder (via select() ou relationships()), usamos ele.
        // Caso contrário, iniciamos um do zero.
        $builder = $this->currentBuilder ?? app($this->entityClass)->newQuery();
        return $this->applyQueryScope($builder);
    }

    /**
     * Conexão do banco derivada do próprio model da entidade.
     *
     * Garante que operações de SQL raw e materialized views usem a MESMA
     * conexão dos métodos de consulta (get/first/where/...), respeitando o
     * $connection definido no model (réplicas, banco por tenant, schema próprio).
     */
    protected function connection(): \Illuminate\Database\Connection
    {
        return app($this->entityClass)->getConnection();
    }
    // =========================================================================
    // SOFT DELETE / ESCOPO
    // =========================================================================

    protected function Trashed(): bool
    {
        return $this->hasContainsSoftDelete && $this->permission;
    }

    public function useTrashed(bool $permission): static
    {
        $this->permission      = $permission;
        $this->onlyTrashedMode = false;
        return $this;
    }

    /**
     * Ativa o modo somente registros soft-deleted (deleted_at IS NOT NULL).
     * Funciona com get(), first(), findById(), findWhere(), paginate(), count(), etc.
     * Lança RuntimeException se o model não usar a trait SoftDeletes.
     *
     * Uso:
     *   $repository->onlyTrashed()->get();
     *   $repository->onlyTrashed()->paginate(15);
     *   $repository->onlyTrashed()->relationships('items')->get();
     */
    public function onlyTrashed(): static
    {
        if (!$this->hasContainsSoftDelete) {
            throw new \RuntimeException(
                "onlyTrashed() não pode ser usado: o model [{$this->getEntityClassName()}] não implementa SoftDeletes."
            );
        }

        $this->onlyTrashedMode = true;
        $this->permission      = false;
        return $this;
    }

    /**
     * Ponto central de aplicação de escopo de exclusão lógica.
     *
     *  - onlyTrashedMode → onlyTrashed(): somente registros excluídos
     *  - permission true  → withTrashed(): todos (ativos + excluídos)
     *  - padrão           → query normal (somente ativos)
     */
    private function applyQueryScope($query)
    {
        if ($this->onlyTrashedMode) {
            $query = $query->onlyTrashed();
        } elseif ($this->Trashed()) {
            $query = $query->withTrashed(true);
        }

        if ($this->limitValue !== null) {
            $query = $query->limit($this->limitValue);
        }

        return $query;
    }

    // Alias de compatibilidade interna
    private function applySoftDeletes($query)
    {
        return $this->applyQueryScope($query);
    }

    /**
     * Sanitiza dados de entrada para segurança.
     * Remove tags HTML se configurado.
     *
     * @param array $data Dados a sanitizar
     * @return array Dados sanitizados
     */
    protected function sanitizeData(array $data): array
    {
        $config = config('repository.sanitization', ['enabled' => true, 'strip_tags' => true]);

        if (!($config['enabled'] ?? true)) {
            return $data;
        }

        $allowedTags = $config['allowed_tags'] ?? [];

        return array_map(function ($value) use ($allowedTags) {
            // Só sanitiza strings
            if (is_string($value)) {
                return strip_tags($value, $allowedTags);
            }
            return $value;
        }, $data);
    }

    // =========================================================================
    // CACHE
    // =========================================================================

    private function getQualifyTagCache(string $method, array $parameters = []): string
    {
        $entityClass = $this->getEntityClassName();

        $queryState = [
            'params'      => $parameters,
            'with'        => $this->relationships,
            'tags'        => $this->tags,
            'onlyTrashed' => $this->onlyTrashedMode,
            'activeView'  => $this->activeView,
        ];

        $paramsHash = ':' . md5(json_encode($queryState, JSON_THROW_ON_ERROR));
        $name = "repo:{$entityClass}:{$method}{$paramsHash}";

        if ($this->Trashed())       $name .= ':trashed';
        if ($this->onlyTrashedMode) $name .= ':only_trashed';

        return $name;
    }

    protected function getEntityClassName(): string
    {
        return ltrim($this->entity(), '\\');
    }

    protected function supportsTags(): bool
    {
        $driver = Cache::getDefaultDriver();
        return !in_array($driver, \RiseTechApps\Repository\Repository::$driverNotSupported);
    }

    public function rememberCache(callable $call, string $method, array $parameters = [])
    {
        $startTime = microtime(true);

        // withoutCache() ativo: executa direto sem armazenar ou consultar o cache
        if ($this->bypassCache) {
            $result = $call();
            $this->trackQueryMetrics($startTime, $method);
            self::$metrics['total_cache_misses']++;
            return $result;
        }

        $cacheKey = $this->getQualifyTagCache($method, $parameters);
        $ttl = $this->getCacheTtl();

        // A tag da entidade está sempre presente (garante que clearCacheForEntity
        // continue invalidando tudo). As tags de setTags()/withCacheTags() são
        // anexadas como pontos de invalidação adicionais.
        $store = $this->supportsTags()
            ? Cache::tags(array_merge([$this->entity()], $this->tags))
            : Cache::store();

        // Hit → devolve direto do cache
        if ($store->has($cacheKey)) {
            self::$metrics['total_cache_hits']++;
            $this->trackQueryMetrics($startTime, $method);
            return $store->get($cacheKey);
        }

        // Miss → executa a query
        self::$metrics['total_cache_misses']++;
        $result = $call();

        // cacheIf(): só grava se não houver condição ou se a condição aprovar o resultado.
        // Sem cacheIf(), o comportamento é idêntico ao anterior (sempre cacheia).
        if ($this->cacheCondition === null || ($this->cacheCondition)($result)) {
            $store->put($cacheKey, $result, $ttl);
        }

        $this->trackQueryMetrics($startTime, $method);

        return $result;
    }

    /**
     * Retorna o TTL efetivo do cache.
     * Usa customCacheTtlMinutes se definido, senão usa defaultCacheTtlMinutes.
     */
    protected function getCacheTtl(): Carbon
    {
        $minutes = $this->customCacheTtlMinutes ?? $this->defaultCacheTtlMinutes;
        return Carbon::now()->addMinutes($minutes);
    }

    /**
     * Define o TTL customizado para a próxima operação.
     * Encadeável. O TTL é resetado após cada operação terminal.
     *
     * Uso:
     *   $repository->cacheFor(5)->get();        // cache por 5 minutos
     *   $repository->cacheForHours(2)->get();    // cache por 2 horas
     *   $repository->cacheForDays(1)->get();    // cache por 1 dia
     */
    public function cacheFor(int $minutes): static
    {
        $this->customCacheTtlMinutes = $minutes;
        return $this;
    }

    /**
     * Define o TTL em horas para a próxima operação.
     */
    public function cacheForHours(int $hours): static
    {
        return $this->cacheFor($hours * 60);
    }

    /**
     * Define o TTL em dias para a próxima operação.
     */
    public function cacheForDays(int $days): static
    {
        return $this->cacheFor($days * 24 * 60);
    }

    public function clearCacheForEntity(string $method = '', array $parameters = []): void
    {
        $this->flushEntityCache();

        try {
            // Cache warming controlado por config — re-aquece o cache recém-limpo.
            if (config('repository.cache.warming_enabled', true)) {
                $methods = $this->resolveWarmingMethods();

                if (!empty($methods)) {
                    dispatch(new RegenerateCacheJob($this, $methods));
                }
            }

            // Só refaz views se o repositório de fato declarar alguma.
            // Evita job e serialização de auth desnecessários em repos sem view.
            if (!empty($this->registerViews())) {
                dispatch(new RefreshMaterializedViewsJob($this, ['auth' => auth()->user()]));
            }

        } catch (\Exception $exception) {
            Log::error("Error processing cache clearing for {$this->getEntityClassName()}: " . $exception->getMessage());
        }
    }

    /**
     * Estratégia de invalidação de cache da entidade.
     *
     * Default: flush total das tags da entidade (seguro — nunca serve stale,
     * porém grosso: um write zera todo o cache da entidade).
     *
     * Para invalidação GRANULAR (opt-in), sobrescreva este método no repositório:
     *   - Marque as leituras com withCacheTags(['clientes:empresa:5']) (item tags).
     *   - Aqui, invalide apenas os grupos afetados com flushTags([...]).
     *   - Para invalidação ciente do registro alterado, prefira ouvir os eventos
     *     RepositoryCreated/Updated/Deleted (que carregam o model) e chamar
     *     flushTags() de lá.
     *
     * Atenção: invalidação parcial mal planejada pode deixar caches de listagem
     * (get/findWhere) stale — por isso o default permanece o flush total.
     */
    protected function flushEntityCache(): void
    {
        if (!$this->supportTag) {
            return;
        }

        $tag = $this->getEntityClassName();
        Cache::tags([$tag])->flush();

        $apiResponseTag = str_replace('\\', '.', $tag);
        Cache::tags([$apiResponseTag, 'api_response'])->flush();
    }

    /**
     * Traduz os warming_methods do config (nomes amigáveis) para as constantes
     * de método que o RegenerateCacheJob entende. 'findById' é ignorado porque
     * depende de um id que não existe no contexto de invalidação.
     */
    protected function resolveWarmingMethods(): array
    {
        $map = [
            'get'       => Repository::$methodAll,
            'first'     => Repository::$methodFirst,
            'dataTable' => Repository::$methodDataTable,
        ];

        $configured = config('repository.cache.warming_methods', ['get', 'first']);

        return array_values(array_filter(array_map(
            fn($method) => $map[$method] ?? null,
            $configured
        )));
    }

    /**
     * Invalida o cache associado às tags informadas.
     *
     * Complementa o clearCacheForEntity() (que limpa a entidade inteira),
     * permitindo invalidação granular pelos grupos definidos em
     * setTags()/withCacheTags().
     *
     * No-op quando o driver de cache não suporta tags (ex.: file, database).
     *
     * Uso:
     *   $repository->flushTags(['clientes:ativos']);
     *   $repository->flushTags(['empresa:5', 'empresa:5:clientes']);
     *
     * @param array $tags Tags a invalidar
     */
    public function flushTags(array $tags): void
    {
        if (!$this->supportTag || empty($tags)) {
            return;
        }

        try {
            Cache::tags($tags)->flush();
        } catch (\Throwable $e) {
            throw CacheOperationException::flushFailed($tags, $e);
        }
    }

    // =========================================================================
    // LEITURA
    // =========================================================================

    /**
     * Define o ID para operações subsequentes (delete, restore, forceDelete).
     *
     * ATENÇÃO: Este método retorna o próprio repository (para encadeamento),
     * NÃO o model/entidade. Para buscar o model, use findById().
     *
     * Uso:
     *   $repository->find(1)->delete();     // Encadeado com delete()
     *   $repository->find(1)->restore();    // Encadeado com restore()
     *
     * @param int|string $id ID do registro
     * @return static Retorna $this para encadeamento
     */
    public function find($id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * Define o ID para operações subsequentes.
     * Alternativa mais explícita ao find().
     *
     * Uso:
     *   $repository->setId(1)->delete();
     *   $repository->setId(1)->restore();
     *
     * @param int|string $id ID do registro
     * @return static Retorna $this para encadeamento
     */
    public function setId($id): static
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return TModel|null
     */
    public function first()
    {
        // Se já temos um builder em andamento, usa ele
        if ($this->currentBuilder) {
            $result = $this->rememberCache(function () {
                return $this->currentBuilder->first();
            }, Repository::$methodFirst);

            $this->resetScope();
            return $result;
        }

        if ($this->shouldUseView()) {
            $result = $this->rememberCache(function () {
                return $this->viewQuery()->first();
            }, Repository::$methodFirst);

            $this->resetScope();
            return $result;
        }

        $result = $this->rememberCache(function () {
            return $this->newQuery()->first();
        }, Repository::$methodFirst);

        $this->resetScope();
        return $result;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, TModel>
     */
    public function get()
    {
        if ($this->shouldUseView()) {
            // ✅ CORREÇÃO: Agora usa cache para views materializadas
            $result = $this->rememberCache(function () {
                return $this->viewQuery()->get();
            }, Repository::$methodAll);

            $this->resetScope();
            return collect($result);
        }

        $result = $this->rememberCache(function () {
            return $this->newQuery()->get();
        }, Repository::$methodAll);

        $this->resetScope();
        return $result;
    }

    /**
     * @param int|string $id
     * @return TModel|null
     */
    public function findById($id)
    {
        $result = $this->rememberCache(function () use ($id) {
            return $this->newQuery()->find($id);
        }, Repository::$methodFind, [$id]);

        $this->resetScope();
        return $result;
    }

    /**
     * Busca um registro pelo ID e lança EntityNotFoundException se não existir.
     * Variante "estrita" do findById() — útil quando a ausência do registro
     * deve interromper o fluxo (ex.: rotas que esperam o recurso existir).
     *
     * Uso:
     *   $client = $repository->findOrFail($id);
     *
     * @param int|string $id
     * @return TModel
     * @throws EntityNotFoundException
     */
    public function findOrFail($id)
    {
        $result = $this->findById($id);

        if ($result === null) {
            throw new EntityNotFoundException($this->getEntityClassName(), $id);
        }

        return $result;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, TModel>
     */
    public function findWhere(array $conditions)
    {
        $result = $this->rememberCache(function () use ($conditions) {
            $query = $this->newQuery();

            foreach ($conditions as $column => $value) {
                $query = $query->where($column, $value);
            }
            return $query->get();
        }, Repository::$methodFindWhere, [$conditions]);

        $this->resetScope();
        return $result;
    }

    public function findWhereCustom(array $conditions)
    {
        $result = $this->rememberCache(function () use ($conditions) {
            $query = $this->newQuery();

            foreach ($conditions as $filter) {
                $this->applyCustomFilter($query, $filter);
            }

            return $query->get();
        }, Repository::$methodFindWhereCustom, [$conditions]);

        $this->resetScope();
        return $result;
    }

    /**
     * Filtra registros por data.
     *
     * Uso:
     *   $repository->whereDate('created_at', '>=', '2024-01-01')->get();
     *   $repository->whereDate('created_at', '=', now()->format('Y-m-d'))->get();
     *
     * @param string $column Coluna de data
     * @param string $operator Operador (=, <, >, <=, >=)
     * @param mixed $value Valor da data (string ou Carbon)
     * @return static
     */
    public function whereDate(string $column, string $operator, $value): static
    {
        $query = $this->shouldUseView() ? $this->viewQuery() : $this->newQuery();
        $this->currentBuilder = $query->whereDate($column, $operator, $value);
        return $this;
    }

    /**
     * Filtra registros com uma condição WHERE simples.
     *
     * Uso:
     *   $repository->where('status', 'ativo')->get();
     *   $repository->where('valor', '>', 100)->get();
     *
     * @param string $column Coluna para filtrar
     * @param mixed $operator Operador ou valor (se 2 argumentos)
     * @param mixed $value Valor a comparar (se 3 argumentos)
     * @return static
     */
    public function where(string $column, $operator = null, $value = null): static
    {
        // Se estiver usando view materializada, usa viewQuery()
        $query = $this->shouldUseView() ? $this->viewQuery() : $this->newQuery();

        if (func_num_args() === 2) {
            $this->currentBuilder = $query->where($column, $operator);
        } else {
            $this->currentBuilder = $query->where($column, $operator, $value);
        }
        return $this;
    }

    /**
     * Filtra registros onde a coluna está nos valores informados.
     *
     * Uso:
     *   $repository->whereIn('status', ['ativo', 'pendente'])->get();
     *   $repository->whereIn('id', [1, 2, 3, 4, 5])->get();
     *
     * @param string $column Coluna para filtrar
     * @param array $values Array de valores permitidos
     * @return static
     */
    public function whereIn(string $column, array $values): static
    {
        $query = $this->shouldUseView() ? $this->viewQuery() : $this->newQuery();
        $this->currentBuilder = $query->whereIn($column, $values);
        return $this;
    }

    /**
     * Filtra registros onde a coluna está entre dois valores.
     *
     * Uso:
     *   $repository->whereBetween('valor', [100, 500])->get();
     *   $repository->whereBetween('created_at', ['2024-01-01', '2024-12-31'])->get();
     *
     * @param string $column Coluna para filtrar
     * @param array $values Array com [minimo, maximo]
     * @return static
     */
    public function whereBetween(string $column, array $values): static
    {
        $query = $this->shouldUseView() ? $this->viewQuery() : $this->newQuery();
        $this->currentBuilder = $query->whereBetween($column, $values);
        return $this;
    }

    /**
     * Agrupa resultados por coluna(s).
     * Útil para consultas com aggregate functions.
     *
     * Uso:
     *   $repository->groupBy('status')->get();
     *   $repository->groupBy(['status', 'tipo'])->get();
     *
     * @param string|array $columns Coluna(s) para agrupar
     * @return static
     */
    public function groupBy(string|array $columns): static
    {
        $this->currentBuilder = $this->newQuery()->groupBy($columns);
        return $this;
    }

    public function findWhereEmail($valor)
    {
        $result = $this->rememberCache(function () use ($valor) {
            return $this->newQuery()->where('email', $valor)->get();
        }, Repository::$methodFindWhereEmail, [$valor]);

        $this->resetScope();
        return $result;
    }

    public function findWhereFirst($column, $valor)
    {
        if ($this->shouldUseView()) {
            // ✅ CORREÇÃO: Agora usa cache para views materializadas
            // E retorna objeto diretamente (não wrap em Collection)
            $result = $this->rememberCache(function () use ($column, $valor) {
                return $this->viewQuery()->where($column, $valor)->first();
            }, Repository::$methodFindWhereFirst, [$column, $valor]);

            $this->resetScope();
            return $result;
        }

        $result = $this->rememberCache(function () use ($column, $valor) {
            return $this->newQuery()->where($column, $valor)->first();
        }, Repository::$methodFindWhereFirst, [$column, $valor]);

        $this->resetScope();
        return $result;
    }

    /**
     * Retorna o total de registros que correspondem ao escopo atual.
     * Compatível com onlyTrashed(), useTrashed() e findWhere().
     *
     * Uso:
     *   $repository->count();
     *   $repository->onlyTrashed()->count();
     *   $repository->useTrashed(true)->count();
     */
    public function count(): int
    {
        $result = $this->newQuery()->count();
        $this->resetScope();
        return $result;
    }

    /**
     * Verifica se existe ao menos um registro no escopo atual.
     * Evita carregar dados desnecessários antes de operações condicionais.
     *
     * Uso:
     *   $repository->exists();
     *   $repository->onlyTrashed()->exists();
     */
    public function exists(): bool
    {
        $result = $this->newQuery()->exists();
        $this->resetScope();
        return $result;
    }

    /**
     * Ordena de forma descendente pela coluna informada (encadeável).
     *
     * Uso:
     *   $repository->latest()->get();
     *   $repository->latest('updated_at')->get();
     */
    public function latest(string $column = 'created_at'): static
    {
        $this->currentBuilder  = $this->newQuery()->latest($column);
        return $this;
    }

    /**
     * Ordena de forma ascendente pela coluna informada (encadeável).
     *
     * Uso:
     *   $repository->oldest()->get();
     *   $repository->oldest('updated_at')->get();
     */
    public function oldest(string $column = 'created_at'): static
    {
        $this->currentBuilder  = $this->newQuery()->oldest($column);
        return $this;
    }

    /**
     * Adiciona contagem de relacionamentos sem carregá-los.
     * Os resultados ficam disponíveis como {relationship}_count em cada registro.
     *
     * Uso:
     *   $repository->withCount('pedidos')->get();
     *   $repository->withCount(['pedidos', 'itens'])->get();
     */
    public function withCount(string|array $relations): static
    {
        $this->currentBuilder  = $this->newQuery()->withCount($relations);
        return $this;
    }

    public function dataTable()
    {
        $result = $this->rememberCache(function () {
            return $this->newQuery()->get();
        }, Repository::$methodDataTable);

        $this->resetScope();
        return $result;
    }

    /**
     * Define ordenação para a query (encadeável).
     * Para views materializadas, funciona em conjunto com where(), paginate(), etc.
     *
     * Uso:
     *   $repository->orderBy('created_at', 'desc')->get();
     *   $repository->useMaterializedView('view')->orderBy('coluna')->paginate(10);
     *
     * @param string $column Coluna para ordenar
     * @param string $order Direção (asc ou desc)
     * @return static
     */
    public function orderBy(string $column, string $order = 'DESC'): static
    {
        $order = strtoupper($order);
        if ($order !== 'DESC' && $order !== 'ASC') {
            $order = 'ASC';
        }

        // Se já temos um builder em andamento, reutiliza
        if ($this->currentBuilder) {
            $this->currentBuilder = $this->currentBuilder->orderBy($column, $order);
        } else {
            $query = $this->shouldUseView() ? $this->viewQuery() : $this->newQuery();
            $this->currentBuilder = $query->orderBy($column, $order);
        }

        return $this;
    }

    // =========================================================================
    // PAGINAÇÃO
    // =========================================================================

    /**
     * Paginação dinâmica baseada em parâmetros do request.
     * sort_column é validado contra allowedSortColumns (proteção contra SQL Injection).
     */
    public function paginate($totalPage = 10): array
    {
        // Para views materializadas ou quando currentBuilder está definido
        if ($this->shouldUseView() || $this->currentBuilder) {
            return $this->paginateWithView($totalPage);
        }

        $request          = request();
        $perPage          = $request->get('pagesize', $totalPage);
        $search           = $request->get('search');
        $searchableFields = $request->get('searchable_fields', []);

        $query = $this->newQuery();

        if (!empty(trim($search ?? '')) && !empty($searchableFields)) {
            $query->where(function ($mainQuery) use ($search, $searchableFields) {
                foreach ($searchableFields as $index => $field) {
                    $dbField = str_replace('.', '->>', $field);
                    $index === 0
                        ? $mainQuery->where($dbField, 'ILIKE', "%{$search}%")
                        : $mainQuery->orWhere($dbField, 'ILIKE', "%{$search}%");
                }
            });
        }

        $sortColumn    = $this->resolveSortColumn($request->get('sort_column', 'id'));
        $sortDirection = in_array(strtolower($request->get('sort_direction', 'asc')), ['asc', 'desc'])
            ? $request->get('sort_direction', 'asc')
            : 'asc';

        $query->orderBy($sortColumn, $sortDirection);

        $data = $query->paginate($perPage);

        $this->resetScope();

        return [
            'data'            => $data->items(),
            'recordsFiltered' => $data->total(),
            'recordsTotal'    => $data->total(),
            'totalPages'      => $data->lastPage(),
            'perPage'         => $data->perPage(),
            'current_page'    => $data->currentPage(),
        ];
    }

    /**
     * Paginação com cache para views materializadas.
     * Cacheia o resultado da paginação baseado nos parâmetros da request.
     */
    protected function paginateWithView($totalPage): array
    {
        $request = request();
        $cacheKey = 'paginate_view_' . md5(serialize($request->all()) . ($this->currentBuilder ? spl_object_hash($this->currentBuilder) : ''));

        return $this->rememberCache(function () use ($totalPage, $request) {
            $perPage          = $request->get('pagesize', $totalPage);
            $search           = $request->get('search');
            $searchableFields = $request->get('searchable_fields', []);

            // Se já temos um builder em andamento (de where(), orderBy(), etc), usa ele
            if ($this->currentBuilder) {
                $query = $this->currentBuilder;
            } else {
                $query = $this->viewQuery();
            }

            if (!empty(trim($search ?? '')) && !empty($searchableFields)) {
                $query->where(function ($mainQuery) use ($search, $searchableFields) {
                    foreach ($searchableFields as $index => $field) {
                        $dbField = str_replace('.', '->>', $field);
                        $index === 0
                            ? $mainQuery->where($dbField, 'ILIKE', "%{$search}%")
                            : $mainQuery->orWhere($dbField, 'ILIKE', "%{$search}%");
                    }
                });
            }

            // Aplica ordenação do request se não houver ordenação manual
            if (!$this->currentBuilder || !str_contains($query->toSql(), 'ORDER BY')) {
                $sortColumn    = $this->resolveSortColumn($request->get('sort_column', 'id'));
                $sortDirection = in_array(strtolower($request->get('sort_direction', 'asc')), ['asc', 'desc'])
                    ? $request->get('sort_direction', 'asc')
                    : 'asc';
                $query->orderBy($sortColumn, $sortDirection);
            }

            $data = $query->paginate($perPage);

            $this->resetScope();

            return [
                'data'            => $data->items(),
                'recordsFiltered' => $data->total(),
                'recordsTotal'    => $data->total(),
                'totalPages'      => $data->lastPage(),
                'perPage'         => $data->perPage(),
                'current_page'    => $data->currentPage(),
            ];
        }, Repository::$methodPaginate, [$request->all()]);
    }

    /**
     * Resolve e valida a coluna de ordenação contra a whitelist.
     * Retorna 'id' como fallback seguro se a coluna não for permitida.
     */
    protected function resolveSortColumn(string $column): string
    {
        $resolved = str_replace('.', '->>', $column);

        if (empty($this->allowedSortColumns)) {
            return 'id';
        }

        return in_array($column, $this->allowedSortColumns) ? $resolved : 'id';
    }

    /**
     * Cursor pagination para grandes datasets.
     * Mais eficiente que offset/limit para grandes volumes.
     *
     * @param int $perPage Registros por página
     * @return \Illuminate\Contracts\Pagination\CursorPaginator
     */
    public function cursorPaginate(int $perPage)
    {
        $result = $this->newQuery()->cursorPaginate($perPage);
        $this->resetScope();
        return $result;
    }

    /**
     * Aplica condicionalmente um callback à query.
     * Útil para lazy loading condicional.
     *
     * Uso:
     *   $repository->when($request->has('with_pedidos'), fn($q) => $q->with('pedidos'))->get();
     *
     * @param mixed $condition Condição para aplicar
     * @param callable $callback Callback para aplicar se condição for verdadeira
     * @return static
     */
    public function when($condition, callable $callback): static
    {
        if ($condition) {
            // Se já temos um builder em andamento, usa ele
            if ($this->currentBuilder) {
                $this->currentBuilder = $callback($this->currentBuilder);
            } else {
                $query = $this->shouldUseView() ? $this->viewQuery() : $this->newQuery();
                $this->currentBuilder = $callback($query);
            }
        }
        return $this;
    }

    /**
     * Seleciona apenas colunas necessárias, incluindo sempre o ID.
     * Otimizado para reduzir transferência de dados.
     *
     * Uso:
     *   $repository->selectOptimized(['nome', 'email'])->get();
     *
     * @param array $columns Colunas a selecionar
     * @return static
     */
    public function selectOptimized(array $columns): static
    {
        // Sempre inclui ID
        if (!in_array('id', $columns)) {
            $columns[] = 'id';
        }

        $this->currentBuilder = $this->newQuery()->select($columns);
        return $this;
    }

    // =========================================================================
    // ESCRITA
    // =========================================================================

    /**
     * Executa o callback dentro de uma transação na conexão do model.
     * Retorna o que o callback retornar. Em caso de deadlock, reexecuta o
     * callback até $attempts vezes (recurso nativo do Laravel).
     *
     * Os jobs de cache (RegenerateCacheJob/RefreshMaterializedViewsJob) são
     * afterCommit, então só disparam após o commit — seguro em rollback.
     *
     * Uso:
     *   $repo->transaction(function () use ($repo) {
     *       $pedido = $repo->store([...]);
     *       $repo->update($id, [...]);
     *       return $pedido;
     *   });
     *
     * @param callable $callback Operações a executar atomicamente
     * @param int $attempts Tentativas em caso de deadlock
     * @return mixed Valor retornado pelo callback
     */
    public function transaction(callable $callback, int $attempts = 1)
    {
        return $this->connection()->transaction($callback, $attempts);
    }

    /**
     * @return TModel|null  Model criado, ou null se um listener cancelar a criação.
     */
    public function store(array $data)
    {
        // Sanitiza dados se habilitado
        $data = $this->sanitizeData($data);

        // Evento antes de criar
        $creatingEvent = new RepositoryCreating($this, null, $data, 'creating');
        event($creatingEvent);

        if (!$creatingEvent->shouldCreate) {
            return null;
        }

        $created = $this->newQuery()->create($data);

        // Evento após criar
        event(new RepositoryCreated($this, $created, $data, 'created'));

        $this->clearCacheForEntity();
        return $created;
    }

    /**
     * Cria múltiplos registros usando Eloquent.
     *
     * Retorna sempre um array com os models criados (pode ser vazio).
     * Dispara eventos Eloquent para cada registro criado.
     *
     * Uso:
     *   $models = $repository->storeMany([
     *       ['nome' => 'A', 'email' => 'a@a.com'],
     *       ['nome' => 'B', 'email' => 'b@b.com'],
     *   ]);
     *
     * @param array $records Array de arrays associativos com dados dos registros
     * @return array Array de models criados (vazio se $records for vazio)
     */
    public function storeMany(array $records): array
    {
        if (empty($records)) {
            return [];
        }

        $created = [];
        foreach ($records as $data) {
            $created[] = $this->newQuery()->create($data);
        }
        $this->clearCacheForEntity();

        return $created;
    }

    /**
     * Realiza insert ou update em lote (upsert).
     * Registros existentes são atualizados, novos são criados.
     *
     * @param array $records Array de registros para inserir/atualizar
     * @param array $uniqueBy Colunas para identificar registros existentes (ex: ['email'] ou ['id'])
     * @return array Array com models criados/atualizados
     */
    public function upsert(array $records, array $uniqueBy): array
    {
        if (empty($records)) {
            return [];
        }

        $results = [];

        foreach ($records as $data) {
            // Busca por chave única
            $query = $this->newQuery()->newQuery();
            foreach ($uniqueBy as $column) {
                if (isset($data[$column])) {
                    $query->where($column, $data[$column]);
                }
            }

            $existing = $query->first();

            if ($existing) {
                // Atualiza
                $existing->update($data);
                $results[] = $existing->fresh();
            } else {
                // Cria novo
                $results[] = $this->newQuery()->create($data);
            }
        }

        $this->clearCacheForEntity();

        return $results;
    }

    /**
     * Busca o model diretamente no banco (sem cache) antes de atualizar,
     * evitando atualizar um objeto stale retornado pelo cache.
     */
    public function update($id, array $data)
    {
        $model = $this->newQuery()->find($id);

        if (!$model) {
            return false;
        }

        // Sanitiza dados se habilitado
        $data = $this->sanitizeData($data);

        // Detectar mudanças
        $changes = [];
        foreach ($data as $key => $value) {
            if ($model->getAttribute($key) !== $value) {
                $changes[$key] = [
                    'old' => $model->getAttribute($key),
                    'new' => $value,
                ];
            }
        }

        // Evento antes de atualizar
        $updatingEvent = new RepositoryUpdating($this, $model, $data, $changes, 'updating');
        event($updatingEvent);

        if (!$updatingEvent->shouldUpdate) {
            return false;
        }

        $updated = $model->update($data);

        // Recarregar model com dados atualizados
        $model->fresh();

        // Evento após atualizar
        event(new RepositoryUpdated($this, $model, $data, $changes, 'updated'));

        $this->clearCacheForEntity();

        return $updated;
    }

    /**
     * Atualiza múltiplos registros que correspondam às condições informadas.
     * Executa uma única query UPDATE com WHERE, sem carregar models em memória.
     *
     * Uso:
     *   $repository->updateMany(['status' => 'inativo'], ['plano_id' => 3]);
     *   $repository->updateMany(['ativo' => false], ['empresa_id' => 10, 'tipo' => 'free']);
     *
     * @param array $data        Campos e valores a atualizar
     * @param array $conditions  Condições WHERE (coluna => valor)
     */
    public function updateMany(array $data, array $conditions): int
    {
        $query = $this->newQuery()->newQuery();

        foreach ($conditions as $column => $value) {
            $query->where($column, $value);
        }

        $affected = $query->update($data);
        $this->clearCacheForEntity();

        return $affected;
    }

    /**
     * Busca no banco (sem cache) e cria ou atualiza conforme existência.
     */
    public function createOrUpdate($id, array $data)
    {
        $existing = $this->newQuery()->find($id);

        if ($existing === null) {
            return $this->store($data);
        }

        return $this->update($id, $data);
    }

    /**
     * Retorna o primeiro registro que corresponda aos atributos, ou cria um novo.
     *
     * Uso:
     *   $client = $repository->firstOrCreate(['email' => 'joao@email.com'], ['nome' => 'João']);
     *
     * @param array $attributes Atributos para buscar
     * @param array $values Valores adicionais ao criar (opcional)
     * @return TModel|null O model encontrado ou criado
     */
    public function firstOrCreate(array $attributes, array $values = [])
    {
        $result = $this->rememberCache(function () use ($attributes) {
            return $this->newQuery()->where($attributes)->first();
        }, Repository::$methodFirst, [$attributes]);

        if ($result) {
            $this->resetScope();
            return $result;
        }

        $this->resetScope();
        return $this->store(array_merge($attributes, $values));
    }

    /**
     * Atualiza ou cria um registro.
     *
     * Uso:
     *   $client = $repository->updateOrCreate(['email' => 'joao@email.com'], ['nome' => 'João Novo']);
     *
     * @param array $attributes Atributos para buscar
     * @param array $values Valores para atualizar/criar
     * @return TModel|bool|null Model criado (caminho store) ou bool do update se já existia.
     */
    public function updateOrCreate(array $attributes, array $values = [])
    {
        $result = $this->rememberCache(function () use ($attributes) {
            return $this->newQuery()->where($attributes)->first();
        }, Repository::$methodFirst, [$attributes]);

        if ($result) {
            $this->resetScope();
            return $this->update($result->getKey(), $values);
        }

        $this->resetScope();
        return $this->store(array_merge($attributes, $values));
    }

    /**
     * Duplica um registro com modificações opcionais.
     *
     * Uso:
     *   $newClient = $repository->duplicate(1, ['nome' => 'Cópia do Cliente']);
     *
     * @param mixed $id ID do registro a duplicar
     * @param array $modifications Valores a substituir (opcional)
     * @return TModel|null O novo model criado, ou null se o original não existir.
     */
    public function duplicate($id, array $modifications = [])
    {
        $original = $this->findById($id);

        if (!$original) {
            return null;
        }

        $data = $original->toArray();

        // Remove campos auto-incrementados
        unset($data['id']);
        unset($data['created_at']);
        unset($data['updated_at']);
        unset($data['deleted_at']);

        // Aplica modificações
        $data = array_merge($data, $modifications);

        $this->resetScope();
        return $this->store($data);
    }

    /**
     * Incrementa uma coluna numérica atomicamente.
     *
     * Uso:
     *   $repository->increment(1, 'visitas');      // +1
     *   $repository->increment(1, 'estoque', 5);  // +5
     *
     * @param mixed $id ID do registro
     * @param string $column Nome da coluna
     * @param int $amount Quantidade a incrementar
     * @return bool
     */
    public function increment($id, string $column, int $amount = 1): bool
    {
        $result = $this->newQuery()->whereKey($id)->increment($column, $amount);
        $this->clearCacheForEntity();
        return $result > 0;
    }

    /**
     * Decrementa uma coluna numérica atomicamente.
     *
     * Uso:
     *   $repository->decrement(1, 'estoque');     // -1
     *   $repository->decrement(1, 'estoque', 5);    // -5
     *
     * @param mixed $id ID do registro
     * @param string $column Nome da coluna
     * @param int $amount Quantidade a decrementar
     * @return bool
     */
    public function decrement($id, string $column, int $amount = 1): bool
    {
        $result = $this->newQuery()->whereKey($id)->decrement($column, $amount);
        $this->clearCacheForEntity();
        return $result > 0;
    }

    /**
     * Processa registros em lotes para evitar estouro de memória em grandes volumes.
     * O callback recebe uma Collection com $size registros por vez.
     * Compatível com onlyTrashed() e useTrashed().
     *
     * Uso:
     *   $repository->chunk(500, function ($registros) {
     *       foreach ($registros as $registro) { ... }
     *   });
     *
     *   $repository->onlyTrashed()->chunk(200, function ($registros) {
     *       // processa somente excluídos em lotes
     *   });
     */
    public function chunk(int $size, callable $callback): bool
    {
        $result = $this->newQuery()->chunk($size, $callback);
        $this->resetScope();
        return $result;
    }

    // =========================================================================
    // EXCLUSÃO
    // =========================================================================

    public function delete(): bool
    {
        $model = $this->newQuery()->find($this->id);

        if (!$model) return false;

        // Evento antes de deletar
        $deletingEvent = new RepositoryDeleting($this, $model, [], 'deleting');
        event($deletingEvent);

        if (!$deletingEvent->shouldDelete) {
            return false;
        }

        foreach ($this->relationships as $relationship) {
            $model->$relationship()->delete();
        }

        $deleted = $model->delete();

        // Evento após deletar (soft delete)
        event(new RepositoryDeleted($this, $model, [], 'deleted', true));

        $this->clearCacheForEntity();

        return (bool) $deleted;
    }

    public function restore(): bool
    {
        $model = $this->newQuery()->withTrashed(true)->find($this->id);

        if (!$model) return false;

        foreach ($this->relationships as $relationship) {
            $model->$relationship()->restore();
        }

        $restored = $model->restore();
        $this->clearCacheForEntity();

        return (bool) $restored;
    }

    public function forceDelete(): bool
    {
        $model = $this->newQuery()->withTrashed(true)->find($this->id);

        if (!$model) return false;

        if ($model->trashed()) {
            foreach ($this->relationships as $relationship) {
                $model->$relationship()->whereNotNull('deleted_at')->forceDelete();
            }

            $deleted = $model->forceDelete();
            $this->clearCacheForEntity();

            return (bool) $deleted;
        }

        return false;
    }

    /**
     * Realiza soft delete em múltiplos registros que correspondam às condições.
     *
     * @param array $conditions Condições where (coluna => valor) ou array de filtros
     * @return int Número de registros excluídos
     */
    public function deleteMany(array $conditions): int
    {
        if (!$this->hasContainsSoftDelete) {
            throw new \RuntimeException(
                "deleteMany() não pode ser usado: o model [{$this->getEntityClassName()}] não implementa SoftDeletes."
            );
        }

        $query = $this->newQuery()->newQuery();

        foreach ($conditions as $column => $value) {
            if (is_array($value) && isset($value['column'])) {
                // Filtro complexo
                $this->applyCustomFilter($query, $value);
            } else {
                // Condição simples
                $query->where($column, $value);
            }
        }

        $count = $query->delete();
        $this->clearCacheForEntity();

        return $count;
    }

    /**
     * Restaura múltiplos registros soft-deleted.
     *
     * @param array $conditions Condições para identificar registros a restaurar
     * @return int Número de registros restaurados
     */
    public function restoreMany(array $conditions): int
    {
        if (!$this->hasContainsSoftDelete) {
            throw new \RuntimeException(
                "restoreMany() não pode ser usado: o model [{$this->getEntityClassName()}] não implementa SoftDeletes."
            );
        }

        $query = $this->newQuery()->newQuery()->onlyTrashed();

        foreach ($conditions as $column => $value) {
            if (is_array($value) && isset($value['column'])) {
                $this->applyCustomFilter($query, $value);
            } else {
                $query->where($column, $value);
            }
        }

        $count = $query->restore();
        $this->clearCacheForEntity();

        return $count;
    }

    /**
     * Força exclusão permanente de múltiplos registros.
     *
     * @param array $conditions Condições para identificar registros
     * @return int Número de registros excluídos permanentemente
     */
    public function forceDeleteMany(array $conditions): int
    {
        if (!$this->hasContainsSoftDelete) {
            throw new \RuntimeException(
                "forceDeleteMany() não pode ser usado: o model [{$this->getEntityClassName()}] não implementa SoftDeletes."
            );
        }

        $query = $this->newQuery()->newQuery()->withTrashed(true);

        foreach ($conditions as $column => $value) {
            if (is_array($value) && isset($value['column'])) {
                $this->applyCustomFilter($query, $value);
            } else {
                $query->where($column, $value);
            }
        }

        // Processar relacionamentos configurados
        if (!empty($this->relationships)) {
            $records = $query->get();
            foreach ($records as $model) {
                foreach ($this->relationships as $relationship) {
                    $model->$relationship()->withTrashed(true)->whereNotNull('deleted_at')->forceDelete();
                }
            }
        }

        $count = $query->forceDelete();
        $this->clearCacheForEntity();

        return $count;
    }

    // =========================================================================
    // FILTROS CUSTOMIZADOS
    // =========================================================================

    private function applyCustomFilter(&$query, array $filter, $isOr = false): void
    {
        if (isset($filter['orGroup']) && is_array($filter['orGroup'])) {
            $query = $query->where(function ($q) use ($filter) {
                foreach ($filter['orGroup'] as $groupFilter) {
                    $this->applyCustomFilter($q, $groupFilter, true);
                }
            });
            return;
        }

        if (isset($filter['andGroup']) && is_array($filter['andGroup'])) {
            $query = $query->where(function ($q) use ($filter) {
                foreach ($filter['andGroup'] as $groupFilter) {
                    $this->applyCustomFilter($q, $groupFilter, false);
                }
            });
            return;
        }

        if (!isset($filter['column'], $filter['operator'])) {
            return;
        }

        $column   = $filter['column'];
        $operator = strtoupper($filter['operator']);
        $value    = $filter['value'] ?? null;

        // Validação de operador permitido
        if (!in_array($operator, $this->allowedOperators, true)) {
            throw InvalidFilterException::invalidOperator($operator, $filter);
        }

        if ($operator === 'BETWEEN' && is_array($value) && count($value) === 2) {
            $isOr ? $query = $query->orWhereBetween($column, $value) : $query = $query->whereBetween($column, $value);
            return;
        }

        if ($operator === 'IN' && is_array($value)) {
            $isOr ? $query = $query->orWhereIn($column, $value) : $query = $query->whereIn($column, $value);
            return;
        }

        if ($operator === 'IS' && $value === null) {
            $isOr ? $query = $query->orWhereNull($column) : $query = $query->whereNull($column);
            return;
        }

        if ($operator === 'IS NOT' && $value === null) {
            $isOr ? $query = $query->orWhereNotNull($column) : $query = $query->whereNotNull($column);
            return;
        }

        if ($operator === 'LIKE') {
            $isOr
                ? $query = $query->orWhere($column, 'LIKE', "%{$value}%")
                : $query = $query->where($column, 'LIKE', "%{$value}%");
            return;
        }

        $isOr
            ? $query = $query->orWhere($column, $operator, $value)
            : $query = $query->where($column, $operator, $value);
    }

    // =========================================================================
    // BUSCAS AVANÇADAS
    // =========================================================================

    /**
     * Busca em campos JSONB (PostgreSQL).
     *
     * Uso:
     *   $repository->findWhereJson('meta.endereco.cidade', 'São Paulo');
     *   $repository->findWhereJson('config.tema', 'dark');
     *
     * @param string $path Caminho no JSON (ex: meta.endereco.cidade)
     * @param mixed $value Valor a buscar
     * @return Collection
     */
    public function findWhereJson(string $path, $value)
    {
        $parts  = explode('.', $path);
        $column = $this->guardColumn(array_shift($parts));

        // Operador JSON nativo do builder: cada segmento é escapado com
        // segurança pelo grammar. As chaves do JSON podem ser arbitrárias
        // (não precisam existir como colunas do schema), por isso não passam
        // pela validação de coluna — só a coluna base é validada.
        $selector = empty($parts) ? $column : $column . '->' . implode('->', $parts);

        $result = $this->rememberCache(function () use ($selector, $value) {
            return $this->newQuery()
                ->where($selector, $value)
                ->get();
        }, Repository::$methodFindWhere, [['json_path' => $path, 'value' => $value]]);

        $this->resetScope();
        return $result;
    }

    /**
     * Busca full-text usando tsvector do PostgreSQL.
     *
     * Uso:
     *   $repository->searchFullText('campos de busca', ['nome', 'descricao', 'tags']);
     *
     * @param string $query Termo de busca
     * @param array $columns Colunas para buscar (devem ter índice tsvector)
     * @return Collection
     */
    public function searchFullText(string $query, array $columns)
    {
        $columns = array_map(fn($column) => $this->guardColumn($column), $columns);

        $result = $this->rememberCache(function () use ($query, $columns) {
            $searchTerm = $this->sanitizeFullTextQuery($query);

            return $this->newQuery()
                ->where(function ($q) use ($searchTerm, $columns) {
                    foreach ($columns as $index => $column) {
                        $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                        $q->$method("to_tsvector('portuguese', \"{$column}\") @@ plainto_tsquery('portuguese', ?)", [$searchTerm]);
                    }
                })
                ->get();
        }, Repository::$methodFindWhere, [['full_text' => $query, 'columns' => $columns]]);

        $this->resetScope();
        return $result;
    }

    /**
     * Busca aproximada (fuzzy) usando similaridade de strings.
     * Requer extensão pg_trgm no PostgreSQL.
     *
     * Uso:
     *   $repository->fuzzySearch('jonh', 'nome'); // Encontra "John"
     *
     * @param string $term Termo aproximado
     * @param string $column Coluna para buscar
     * @return Collection
     */
    public function fuzzySearch(string $term, string $column)
    {
        $column = $this->guardColumn($column);

        $result = $this->rememberCache(function () use ($term, $column) {
            return $this->newQuery()
                ->whereRaw("\"{$column}\" % ?", [$term])
                ->orderByRaw("similarity(\"{$column}\", ?) DESC", [$term])
                ->get();
        }, Repository::$methodFindWhere, [['fuzzy' => $term, 'column' => $column]]);

        $this->resetScope();
        return $result;
    }

    /**
     * Sanitiza query de full-text para segurança.
     */
    private function sanitizeFullTextQuery(string $query): string
    {
        // Remove caracteres especiais que podem causar erros no tsquery
        return preg_replace('/[|&!()<>:*]/', '', $query);
    }

    /**
     * Valida um identificador de coluna antes de concatená-lo em SQL raw.
     * Protege contra SQL injection via nome de coluna.
     *
     * Camadas:
     *  1. Regex de identificador simples — impede fechar aspas/injetar SQL.
     *  2. Whitelist: $allowedColumns (se definida) ou as colunas reais da tabela.
     *
     * Se a tabela não puder ser inspecionada (lista vazia), apenas a regex é
     * aplicada — ainda assim suficiente para barrar injeção.
     *
     * @throws InvalidFilterException
     */
    protected function guardColumn(string $column): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            throw InvalidFilterException::invalidColumn($column);
        }

        $allowed = !empty($this->allowedColumns)
            ? $this->allowedColumns
            : $this->tableColumns();

        if (!empty($allowed) && !in_array($column, $allowed, true)) {
            throw InvalidFilterException::invalidColumn($column);
        }

        return $column;
    }

    /**
     * Retorna as colunas reais da tabela do model (cacheado por 24h).
     * Fonte correta para validar nomes de coluna — diferente de getFillable(),
     * que descreve mass-assignment e não as colunas existentes.
     */
    protected function tableColumns(): array
    {
        $table = app($this->entityClass)->getTable();

        return Cache::remember(
            "repo:columns:{$table}",
            now()->addHours(24),
            fn() => Schema::getColumnListing($table)
        );
    }

    /**
     * Aplica um scope customizado definido no repository.
     *
     * Uso no repository:
     *   protected function scopeAtivos($query) {
     *       return $query->where('status', 'ativo');
     *   }
     *
     * Uso:
     *   $repository->scope('ativos')->get();
     *   $repository->scope('recentes', 7)->get(); // Com parâmetros
     *
     * @param string $scopeName Nome do scope
     * @param mixed ...$parameters Parâmetros para o scope
     * @return static
     */
    public function scope(string $scopeName, ...$parameters): static
    {
        $method = 'scope' . ucfirst($scopeName);

        if (!method_exists($this, $method)) {
            throw new \BadMethodCallException(
                "Scope [{$scopeName}] não existe no repository [" . get_class($this) . "]"
            );
        }

        $this->currentBuilder = $this->$method($this->newQuery(), ...$parameters);

        return $this;
    }

    // =========================================================================
    // MODIFICADORES DE QUERY (encadeáveis)
    // =========================================================================

    public function select(array $columns = ['*']): static
    {
        if (count($columns) === 1 && $columns[0] === '*') {
            return $this;
        }

        $formattedColumns = array_map(function ($col) {
            if (str_contains($col, '.')) {
                $parts       = explode('.', $col);
                $tableColumn = preg_replace('/[^a-z0-9_]/i', '', $parts[0]);
                $jsonKey     = preg_replace('/[^a-z0-9_]/i', '', $parts[1]);
                return DB::raw("\"{$tableColumn}\"->>'{$jsonKey}' as \"{$col}\"");
            }
            return $col;
        }, $columns);

        if (!in_array('id', $columns) && !in_array('id', $formattedColumns)) {
            $formattedColumns[] = 'id';
        }

        $this->currentBuilder  = $this->newQuery()->select($formattedColumns);

        return $this;
    }

    /**
     * Adiciona uma expressão SQL raw ao SELECT.
     * Para views materializadas, permite selecionar colunas calculadas.
     *
     * Uso:
     *   $repository->selectRaw('SUM(valor) as total, COUNT(*) as count')->first();
     *   $repository->useMaterializedView('view')->selectRaw('col1, col2')->get();
     *
     * @param string $expression Expressão SQL
     * @return static
     */
    public function selectRaw(string $expression): static
    {
        $query = $this->shouldUseView() ? $this->viewQuery() : $this->newQuery();
        $this->currentBuilder = $query->selectRaw($expression);
        return $this;
    }

    public function relationships(...$relationships): static
    {
        $this->relationships = [];

        foreach ($relationships as $relationship) {
            if ($this->hasContainsSoftDelete && $this->Trashed()) {
                $this->relationships[$relationship] = function ($query) {
                    $query->withTrashed();
                    // Propagar bypassCache para relacionamentos
                    if ($this->bypassCache) {
                        $query->useWritePdo();
                    }
                };
            } else {
                // Sempre usar callback para poder propagar bypassCache
                $this->relationships[$relationship] = function ($query) {
                    if ($this->bypassCache) {
                        $query->useWritePdo();
                    }
                };
            }
        }

        $this->currentBuilder  = $this->newQuery()->with($this->relationships);

        return $this;
    }

    public function setTags($tags): static
    {
        if ($this->supportTag) {
            $this->tags = $tags;
        }
        return $this;
    }

    /**
     * Define tags hierárquicas para cache.
 * Permite agrupar cache em níveis.
     *
     * Uso:
     *   $repository->withCacheTags(['clientes', 'clientes:ativos'])->get();
     *   $repository->withCacheTags(['empresa:5', 'empresa:5:clientes'])->get();
     *
     * @param array $tags Array de tags hierárquicas
     * @return static
     */
    public function withCacheTags(array $tags): static
    {
        if ($this->supportTag) {
            $this->tags = array_merge($this->tags, $tags);
        }
        return $this;
    }

    /**
     * Define uma condição para cachear o resultado da próxima operação terminal.
     * O callback é chamado APÓS a query (no cache miss) e recebe o resultado;
     * o resultado só é gravado no cache se o callback retornar true.
     * Em caso de cache hit, o callback não é chamado.
     *
     * Útil, por exemplo, para não cachear resultados vazios.
     *
     * Uso:
     *   $repository->cacheIf(fn($result) => $result->isNotEmpty())->get();
     *   $repository->cacheIf(fn($result) => $result !== null)->findById(1);
     *
     * @param callable $condition Callback que recebe o resultado e retorna bool
     * @return static
     */
    public function cacheIf(callable $condition): static
    {
        $this->cacheCondition = $condition;
        return $this;
    }

    /**
     * Pré-aquece o cache para os métodos especificados.
     * Útil para pre-cachear dados frequentemente acessados.
     *
     * Uso:
     *   $repository->warmCache(['get', 'first', 'findById']);
     *
     * @param array $methods Métodos a pré-cachear
     * @return void
     */
    public function warmCache(array $methods): void
    {
        foreach ($methods as $method) {
            match ($method) {
                'get' => $this->get(),
                'first' => $this->first(),
                'findById' => $this->findById(1), // Usa ID 1 como exemplo
                'dataTable' => $this->dataTable(),
                default => null,
            };
        }
    }

    /**
     * Habilita log de queries lentas.
     *
     * Uso:
     *   $repository->enableSlowQueryLog(100)->get(); // Loga queries acima de 100ms
     *
     * @param int $threshold Threshold em milissegundos
     * @return static
     */
    public function enableSlowQueryLog(int $threshold): static
    {
        $this->slowQueryThreshold = $threshold;
        return $this;
    }

    /**
     * Retorna métricas de uso do repository.
     *
     * @return array
     */
    public function getMetrics(): array
    {
        $metrics = self::$metrics;
        $totalCacheAccess = $metrics['total_cache_hits'] + $metrics['total_cache_misses'];

        return [
            'total_queries' => $metrics['total_queries'],
            'total_cache_hits' => $metrics['total_cache_hits'],
            'total_cache_misses' => $metrics['total_cache_misses'],
            'cache_hit_rate' => $totalCacheAccess > 0
                ? round($metrics['total_cache_hits'] / $totalCacheAccess * 100, 2)
                : 0,
            'avg_query_time' => $metrics['avg_query_time'],
            'slow_queries_count' => count($metrics['slow_queries']),
            'entity' => $this->getEntityClassName(),
        ];
    }

    /**
     * Zera as métricas acumuladas.
     *
     * As métricas são estáticas (compartilhadas por todos os repositórios no
     * processo). Em workers long-running (Octane, queue daemon) elas acumulam
     * entre requests/jobs — chame este método no início de cada ciclo para ter
     * métricas por request, ou para isolar medições.
     */
    public static function resetMetrics(): void
    {
        self::$metrics = [
            'total_queries' => 0,
            'total_cache_hits' => 0,
            'total_cache_misses' => 0,
            'slow_queries' => [],
            'avg_query_time' => 0,
        ];
    }

    /**
     * Registra métricas de query.
     */
    protected function trackQueryMetrics(float $startTime, string $method): void
    {
        $duration = (microtime(true) - $startTime) * 1000; // em ms

        self::$metrics['total_queries']++;

        // Atualiza média
        $count = self::$metrics['total_queries'];
        self::$metrics['avg_query_time'] = (
            (self::$metrics['avg_query_time'] * ($count - 1)) + $duration
        ) / $count;

        // Verifica slow query
        if ($this->slowQueryThreshold > 0 && $duration > $this->slowQueryThreshold) {
            self::$metrics['slow_queries'][] = [
                'method' => $method,
                'duration' => round($duration, 2),
                'threshold' => $this->slowQueryThreshold,
                'time' => now()->toIso8601String(),
            ];

            Log::warning('Slow query detected', [
                'method' => $method,
                'entity' => $this->getEntityClassName(),
                'duration_ms' => round($duration, 2),
            ]);
        }
    }

    /**
     * Pula o cache para a próxima operação terminal, indo direto ao banco.
     * Útil para contextos críticos: pós-pagamento, relatórios em tempo real, etc.
     * O cache NÃO é invalidado — apenas ignorado nessa chamada.
     *
     * Uso:
     *   $repository->withoutCache()->get();
     *   $repository->withoutCache()->findById(1);
     *   $repository->withoutCache()->paginate(20);
     */
    public function withoutCache(): static
    {
        $this->bypassCache = true;
        return $this;
    }

    /**
     * Limita o número máximo de registros retornados.
     * Encadeável com get(), first(), orderBy(), latest(), oldest() e onlyTrashed().
     *
     * Uso:
     *   $repository->limit(10)->get();
     *   $repository->latest()->limit(5)->get();
     *   $repository->onlyTrashed()->limit(3)->get();
     */
    public function limit(int $value): static
    {
        $this->limitValue = $value;
        return $this;
    }

    /**
     * Retorna os valores de uma única coluna como Collection.
     * Aceita um segundo parâmetro para usar como chave do mapeamento.
     * Compatível com onlyTrashed(), useTrashed() e withoutCache().
     *
     * Uso:
     *   $repository->pluck('nome');
     *   $repository->pluck('nome', 'id');          // Collection keyed por id
     *   $repository->onlyTrashed()->pluck('email');
     *   $repository->withoutCache()->pluck('nome', 'id');
     */
    public function pluck(string $column, ?string $key = null)
    {
        $query = $this->newQuery();

        $result = $key
            ? $query->pluck($column, $key)
            : $query->pluck($column);

        $this->resetScope();
        return $result;
    }

    /**
     * Retorna a soma dos valores de uma coluna numérica.
     * Compatível com onlyTrashed() e useTrashed().
     *
     * Uso:
     *   $repository->sum('total');
     *   $repository->onlyTrashed()->sum('valor');
     */
    public function sum(string $column): int|float
    {
        $result = $this->newQuery()->sum($column);
        $this->resetScope();
        return $result;
    }

    /**
     * Retorna a média dos valores de uma coluna numérica.
     * Compatível com onlyTrashed() e useTrashed().
     *
     * Uso:
     *   $repository->avg('nota');
     *   $repository->onlyTrashed()->avg('score');
     */
    public function avg(string $column): int|float|null
    {
        $result = $this->newQuery()->avg($column);
        $this->resetScope();
        return $result;
    }

    /**
     * Retorna o menor valor de uma coluna.
     * Compatível com onlyTrashed() e useTrashed().
     *
     * Uso:
     *   $repository->min('preco');
     *   $repository->min('created_at');
     */
    public function min(string $column): mixed
    {
        $result = $this->newQuery()->min($column);
        $this->resetScope();
        return $result;
    }

    /**
     * Retorna o maior valor de uma coluna.
     * Compatível com onlyTrashed() e useTrashed().
     *
     * Uso:
     *   $repository->max('preco');
     *   $repository->max('created_at');
     */
    public function max(string $column): mixed
    {
        $result = $this->newQuery()->max($column);
        $this->resetScope();
        return $result;
    }

    // =========================================================================
    // MATERIALIZED VIEWS
    // =========================================================================

    /**
     * Cria uma definição de view materializada usando Query Builder.
     *
     * Uso:
     *   $this->view('vw_vendas', function ($query) {
     *       return $query->select(['cliente_id', DB::raw('SUM(valor) as total')])
     *                  ->whereNull('deleted_at')
     *                  ->groupBy('cliente_id');
     *   });
     *
     * @param string $name Nome da view
     * @param callable $callback Função que recebe Query Builder
     * @return array ['name' => $name, 'sql' => $sql]
     */
    public function view(string $name, callable $callback): array
    {
        $baseQuery = $this->newQuery()->toBase();
        $builder = $callback($baseQuery);

        // Extrai o SQL e bindings
        $sql = $builder->toSql();
        $bindings = $builder->getBindings();

        // Substitui placeholders pelos valores
        $sql = $this->substituteBindings($sql, $bindings);

        return [
            'name' => $name,
            'sql'  => $sql,
        ];
    }

    /**
     * Substitui placeholders ? pelos valores reais no SQL.
     */
    private function substituteBindings(string $sql, array $bindings): string
    {
        foreach ($bindings as $binding) {
            $value = is_numeric($binding) ? $binding : "'" . addslashes($binding) . "'";
            $sql = preg_replace('/\?/', $value, $sql, 1);
        }
        return $sql;
    }

    /**
     * Mapa de views materializadas do repositório: ['nome_view' => sql|array].
     * Default vazio — repositórios sem views não precisam implementar.
     * Subclasses que usam views materializadas sobrescrevem este método.
     */
    public function registerViews(): array
    {
        return [];
    }

    /**
     * @param bool $strict Quando true, propaga MaterializedViewException em vez
     *                     de apenas logar. Use em comandos artisan (falha-rápido);
     *                     o job de refresh mantém o default false (resiliente).
     */
    public function createMaterializedViews(bool $strict = false): void
    {
        foreach ($this->registerViews() as $view => $query) {
            if (!$this->materializedViewExists($view)) {
                // Suporta tanto string SQL quanto array do método view()
                $sql = is_array($query) ? $query['sql'] : $query;
                $this->createSingleMaterializedView($view, $sql, $strict);
            }
        }
    }

    /**
     * Cria a view materializada e registra-a no catálogo administrativo.
     *
     * Duas operações com conexões DELIBERADAMENTE diferentes:
     *
     *  1. CREATE MATERIALIZED VIEW → connection() (conexão do model).
     *     A view é um objeto físico do Postgres; precisa nascer no banco onde
     *     os dados do model vivem.
     *
     *  2. INSERT em `materialized_views` → DB (conexão default).
     *     Esta tabela NÃO é a view: é um registro administrativo central
     *     (nome, autor, datas) criado pela migration do package na conexão
     *     default. Nenhuma lógica lê esta tabela — quem responde "a view
     *     existe?" é o pg_matviews (ver materializedViewExists()). Por isso o
     *     registro fica centralizado, agregando todas as views de todos os
     *     repositórios num lugar só, independentemente da conexão de cada view.
     */
    protected function createSingleMaterializedView(string $view, string $query, bool $strict = false): void
    {
        try {
            $this->connection()->statement("CREATE MATERIALIZED VIEW {$view} AS {$query}");

            // Catálogo administrativo central (conexão default) — ver docblock.
            DB::table('materialized_views')->updateOrInsert(
                ['name' => $view],
                [
                    'user_id'           => auth()->id(),
                    'created_at'        => now(),
                    'last_refreshed_at' => now(),
                    'active'            => true,
                ]
            );
        } catch (\Throwable $e) {
            if ($strict) {
                throw MaterializedViewException::creationFailed($view, $query, $e);
            }
            Log::error('Erro Register Materialized View', [$e->getMessage()]);
        }
    }

    protected function materializedViewExists(string $view): bool
    {
        $result = $this->connection()->select("
            SELECT 1 FROM pg_matviews WHERE schemaname = 'public' AND matviewname = ?
        ", [$view]);

        return !empty($result);
    }

    /**
     * Before dispara ANTES do refresh.
     * After  dispara DEPOIS do refresh.
     *
     * Conexões deliberadamente diferentes (ver createSingleMaterializedView()):
     *  - REFRESH MATERIALIZED VIEW → connection() (conexão do model, onde a
     *    view física existe).
     *  - UPDATE em `materialized_views` (last_refreshed_at) → DB (conexão
     *    default): apenas atualiza o registro administrativo central; não
     *    afeta a view em si.
     *
     * @param bool $strict Quando true, propaga MaterializedViewException em vez
     *                     de apenas logar. Use em comandos artisan (falha-rápido);
     *                     o job de refresh mantém o default false (resiliente).
     */
    public function refreshMaterializedViews(?string $view = null, bool $concurrently = true, bool $strict = false): void
    {
        $this->createMaterializedViews($strict);

        $views = $view ? [$view] : array_keys($this->registerViews());

        event(new BeforeRefreshAllMaterializedViewsJobEvent());

        foreach ($views as $v) {
            try {
                event(new BeforeRefreshMaterializedViewsJobEvent($v));

                $sql = "REFRESH MATERIALIZED VIEW {$v};";

                $this->connection()->statement($sql);

                // Catálogo administrativo central (conexão default) — ver docblock.
                DB::table('materialized_views')
                    ->where('name', $v)
                    ->update(['last_refreshed_at' => now()]);

                event(new AfterRefreshMaterializedViewsJobEvent($v));

            } catch (\Throwable $e) {
                if ($strict) {
                    throw MaterializedViewException::refreshFailed($v, $e);
                }
                Log::error("Erro ao refresh da materialized view [{$v}]: " . $e->getMessage());
            }
        }

        event(new AfterRefreshAllMaterializedViewsJobEvent());
    }

    public function cleanMaterializedView(): void
    {
        foreach ($this->registerViews() as $view => $query) {
            if ($this->materializedViewExists($view)) {
                $this->connection()->statement("DROP MATERIALIZED VIEW IF EXISTS {$view};");
            }
        }
    }

    public function useMaterializedView(string $view): static
    {
        $this->activeView = $view;

        // Garante que a view existe antes de qualquer consulta.
        // Se não existir, cria automaticamente usando o SQL de registerViews().
        // Evita falhas quando a view ainda não foi criada pelo scheduler
        // ou quando o banco foi recriado em ambientes de desenvolvimento.
        if (!$this->materializedViewExists($view)) {
            $views = $this->registerViews();

            if (isset($views[$view])) {
                // Suporta tanto string SQL quanto array do método view()
                $sql = is_array($views[$view]) ? $views[$view]['sql'] : $views[$view];
                $this->createSingleMaterializedView($view, $sql);
            } else {
                throw MaterializedViewException::viewNotFound($view);
            }
        }

        return $this;
    }

    /**
     * Retorna um query builder para a view ativa, usando a conexão do model.
     *
     * Passa a query pelo hook applyViewScope(), permitindo que repositórios
     * filhos apliquem regras de isolamento sem que o package conheça essa regra.
     */
    protected function viewQuery(): \Illuminate\Database\Query\Builder
    {
        $query = $this->connection()->table($this->activeView);
        return $this->applyViewScope($query);
    }

    /**
     * Hook de escopo para views materializadas.
     *
     * Default: não filtra nada (projeto sem isolamento). Repositórios filhos
     * sobrescrevem este método — diretamente ou via trait — para aplicar regras
     * de isolamento sobre a query da view.
     *
     * Mantém o package agnóstico: a regra de isolamento vive fora do package.
     */
    protected function applyViewScope(
        \Illuminate\Database\Query\Builder $query
    ): \Illuminate\Database\Query\Builder {
        return $query;
    }

    /**
     * Materialized view é bloqueada quando onlyTrashed está ativo,
     * pois views materializadas tipicamente não contêm registros excluídos.
     */
    private function shouldUseView(): bool
    {
        return $this->activeView
            && !$this->Trashed()
            && !$this->onlyTrashedMode;
    }
}
