<?php

namespace RiseTechApps\Repository\Core;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
use RiseTechApps\Repository\Exception\InvalidFilterException;
use RiseTechApps\Repository\Exception\NotEntityDefinedException;
use RiseTechApps\Repository\Jobs\RefreshMaterializedViewsJob;
use RiseTechApps\Repository\Jobs\RegenerateCacheJob;
use RiseTechApps\Repository\Repository;
use RiseTechApps\Tenancy\Enums\SharingPolicy;
use RiseTechApps\Tenancy\Models\SubTenant\SubTenant;

abstract class BaseRepository implements RepositoryInterface
{
    protected ?string $activeView = null;
    protected string $entityClass;
    protected ?Builder $currentBuilder = null;
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
    protected ?callable $cacheCondition = null;

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

    /**
     * @throws NotEntityDefinedException
     */
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
     * Gera uma query limpa. É aqui que o TenancyScope será chamado no momento certo.
     */
    protected function newQuery()
    {
        // Se já iniciamos um builder (via select() ou relationships()), usamos ele.
        // Caso contrário, iniciamos um do zero.
        $builder = $this->currentBuilder ?? app($this->entityClass)->newQuery();
        return $this->applyQueryScope($builder);
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
        ];

        $paramsHash = ':' . md5(json_encode($queryState, JSON_THROW_ON_ERROR | JSON_SORTED_KEYS));
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

        // Verifica se já existe no cache
        $inCache = $this->supportsTags()
            ? Cache::tags([$this->entity()])->has($cacheKey)
            : Cache::has($cacheKey);

        if ($inCache) {
            self::$metrics['total_cache_hits']++;
        } else {
            self::$metrics['total_cache_misses']++;
        }

        $result = $this->supportsTags()
            ? Cache::tags([$this->entity()])->remember($cacheKey, $ttl, $call)
            : Cache::remember($cacheKey, $ttl, $call);

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
        if ($this->supportTag) {
            $tag = $this->getEntityClassName();

            Cache::tags([$tag])->flush();

            $apiResponseTag = str_replace('\\', '.', $tag);
            Cache::tags([$apiResponseTag, 'api_response'])->flush();
        }

        try {
            dispatch(new RegenerateCacheJob($this, [
                Repository::$methodAll,
                Repository::$methodFirst,
            ]));

            dispatch(new RefreshMaterializedViewsJob($this, ['auth' => auth()->user()]));

        } catch (\Exception $exception) {
            Log::error("Error processing cache clearing for {$this->getEntityClassName()}: " . $exception->getMessage());
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

    public function first()
    {
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

    public function findById($id)
    {
        $result = $this->rememberCache(function () use ($id) {
            return $this->newQuery()->find($id);
        }, Repository::$methodFind, [$id]);

        $this->resetScope();
        return $result;
    }

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
        $this->currentBuilder = $this->newQuery()->whereDate($column, $operator, $value);
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
        $this->currentBuilder = $this->newQuery()->whereIn($column, $values);
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
        $this->currentBuilder = $this->newQuery()->whereBetween($column, $values);
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

    public function orderBy($column, $order = 'DESC')
    {
        if (mb_strtoupper($order) !== 'DESC' && mb_strtoupper($order) !== 'ASC') {
            $order = 'ASC';
        }

        $result = $this->rememberCache(function () use ($column, $order) {
            return $this->newQuery()->orderBy($column, $order)->get();
        }, Repository::$methodOrder);

        $this->resetScope();
        return $result;
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
        // Para views materializadas, usar cache
        if ($this->shouldUseView()) {
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
        $cacheKey = 'paginate_view_' . md5(serialize($request->all()));

        return $this->rememberCache(function () use ($totalPage) {
            $request          = request();
            $perPage          = $request->get('pagesize', $totalPage);
            $search           = $request->get('search');
            $searchableFields = $request->get('searchable_fields', []);

            $query = $this->viewQuery();

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
            $this->currentBuilder = $callback($this->newQuery());
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
     * @return mixed O model encontrado ou criado
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
     * @return mixed O model atualizado ou criado
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
     * @param array $modificações Valores a substituir (opcional)
     * @return mixed O novo model criado
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
        $result = $this->rememberCache(function () use ($path, $value) {
            $parts = explode('.', $path);
            $column = array_shift($parts);
            $jsonPath = implode('->', $parts);

            return $this->newQuery()
                ->whereRaw("\"{$column}\"->>'{$jsonPath}' = ?", [$value])
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
     * Define uma condição para cachear o resultado.
     * Só cacheia se o callback retornar true.
     *
     * Uso:
     *   $repository->cacheIf(fn($result) => $result->count() > 0)->get();
     *   $repository->cacheIf(fn() => !$this->isDebugMode())->findById(1);
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

    public function createMaterializedViews(): void
    {
        foreach ($this->registerViews() as $view => $query) {
            if (!$this->materializedViewExists($view)) {
                // Suporta tanto string SQL quanto array do método view()
                $sql = is_array($query) ? $query['sql'] : $query;
                $this->createSingleMaterializedView($view, $sql);
            }
        }
    }

    protected function createSingleMaterializedView(string $view, string $query): void
    {
        try {
            DB::statement("CREATE MATERIALIZED VIEW {$view} AS {$query}");

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
            Log::error('Erro Register Materialized View', [$e->getMessage()]);
        }
    }

    protected function materializedViewExists(string $view): bool
    {
        $result = DB::select("
            SELECT 1 FROM pg_matviews WHERE schemaname = 'public' AND matviewname = ?
        ", [$view]);

        return !empty($result);
    }

    /**
     * Before dispara ANTES do refresh.
     * After  dispara DEPOIS do refresh.
     */
    public function refreshMaterializedViews(?string $view = null, bool $concurrently = true): void
    {
        $this->createMaterializedViews();

        $views = $view ? [$view] : array_keys($this->registerViews());

        event(new BeforeRefreshAllMaterializedViewsJobEvent());

        foreach ($views as $v) {
            try {
                event(new BeforeRefreshMaterializedViewsJobEvent($v));

                $sql = "REFRESH MATERIALIZED VIEW {$v};";

                DB::statement($sql);

                DB::table('materialized_views')
                    ->where('name', $v)
                    ->update(['last_refreshed_at' => now()]);

                event(new AfterRefreshMaterializedViewsJobEvent($v));

            } catch (\Throwable $e) {
                Log::error("Erro ao refresh da materialized view [{$v}]: " . $e->getMessage());
            }
        }

        event(new AfterRefreshAllMaterializedViewsJobEvent());
    }

    public function cleanMaterializedView(): void
    {
        foreach ($this->registerViews() as $view => $query) {
            if ($this->materializedViewExists($view)) {
                DB::statement("DROP MATERIALIZED VIEW IF EXISTS {$view};");
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
                Log::warning("useMaterializedView: view [{$view}] não encontrada em registerViews(). A query pode falhar.");
            }
        }

        return $this;
    }

    /**
     * Retorna um query builder para a view ativa com o scope de subtenant
     * aplicado automaticamente, baseado na SharingPolicy do model.
     *
     * Substitui todos os DB::table($this->activeView) diretos, garantindo
     * que nenhuma consulta a uma view escape sem o filtro de isolamento.
     */
    protected function viewQuery(): \Illuminate\Database\Query\Builder
    {
        $query = DB::table($this->activeView);
        return $this->applyViewScope($query);
    }

    /**
     * Aplica o filtro de subtenant no query builder da view.
     *
     * Lê a SharingPolicy diretamente do model do repository —
     * zero configuração necessária no repository filho.
     *
     * RESTRICTED   → WHERE sub_tenant_id = {filial_ativa}
     * USER_FILIALS → WHERE sub_tenant_id IN {filiais_autorizadas_do_usuario}
     * ALL_FILIALS  → WHERE sub_tenant_id IN {todas_filiais_do_tenant}
     *
     * Sem contexto inicializado → WHERE 1 = 0 (falha segura — retorno vazio)
     */
    protected function applyViewScope(
        \Illuminate\Database\Query\Builder $query
    ): \Illuminate\Database\Query\Builder {

        // Sem subtenant inicializado → falha segura
        if (!subTenancy()->isInitialized()) {
            return $query->whereRaw('1 = 0');
        }

        // Lê a política do model — default RESTRICTED se não declarar
        $model  = app($this->entityClass);
        $policy = method_exists($model, 'sharingPolicy')
            ? $model->sharingPolicy()
            : SharingPolicy::RESTRICTED;

        return match ($policy) {
            SharingPolicy::RESTRICTED   => $this->applyRestrictedViewScope($query),
            SharingPolicy::USER_FILIALS => $this->applyUserFilialsViewScope($query),
            SharingPolicy::ALL_FILIALS  => $this->applyAllFilialsViewScope($query),
        };
    }

    /**
     * RESTRICTED — filtra pela filial ativa no momento.
     */
    private function applyRestrictedViewScope(
        \Illuminate\Database\Query\Builder $query
    ): \Illuminate\Database\Query\Builder {
        $key = subTenancy()->getKey();

        if (!$key) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('sub_tenant_id', $key);
    }

    /**
     * USER_FILIALS — filtra pelas filiais autorizadas do usuário.
     * Quando multi_filial está ativo, considera todas as filiais do contexto.
     */
    private function applyUserFilialsViewScope(
        \Illuminate\Database\Query\Builder $query
    ): \Illuminate\Database\Query\Builder {
        $authorizedIds = subTenancy()->getSharedSubTenant();

        if (empty($authorizedIds)) {
            return $query->whereRaw('1 = 0');
        }

        // multi_filial ativo com múltiplas filiais → agrega via SUM
        // (a view tem 1 linha por filial — precisamos somar)
        if (subTenancy()->getMultiFilial() && count($authorizedIds) > 1) {
            return $query->whereIn('sub_tenant_id', $authorizedIds);
        }

        return $query->where('sub_tenant_id', $authorizedIds[0] ?? subTenancy()->getKey());
    }

    /**
     * ALL_FILIALS — filtra por todas as filiais do tenant.
     */
    private function applyAllFilialsViewScope(
        \Illuminate\Database\Query\Builder $query
    ): \Illuminate\Database\Query\Builder {
        if (!tenancy()->isInitialized()) {
            return $query->whereRaw('1 = 0');
        }

        $allIds = SubTenant::where('tenant_id', tenancy()->getKey())
            ->pluck('id')
            ->toArray();

        if (empty($allIds)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('sub_tenant_id', $allIds);
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
