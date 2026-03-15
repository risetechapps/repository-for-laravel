<?php

namespace RiseTechApps\Repository\Core;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RiseTechApps\Repository\Contracts\RepositoryInterface;
use RiseTechApps\Repository\Events\AfterRefreshMaterializedViewsJobEvent;
use RiseTechApps\Repository\Events\BeforeRefreshMaterializedViewsJobEvent;
use RiseTechApps\Repository\Exception\NotEntityDefinedException;
use RiseTechApps\Repository\Jobs\RefreshMaterializedViewsJob;
use RiseTechApps\Repository\Jobs\RegenerateCacheJob;
use RiseTechApps\Repository\Repository;

abstract class BaseRepository implements RepositoryInterface
{
    protected ?string $activeView = null;

    public $entity;
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
     * Quando true, a próxima operação terminal ignora o cache e vai direto ao banco.
     * Resetado automaticamente pelo resetScope() após cada operação.
     */
    protected bool $bypassCache = false;

    /**
     * Limite de registros a retornar. null = sem limite.
     * Resetado automaticamente pelo resetScope() após cada operação.
     */
    protected ?int $limitValue = null;

    /**
     * @throws NotEntityDefinedException
     */
    public function __construct()
    {
        $this->entity               = $this->resolveEntity();
        $this->hasContainsSoftDelete = $this->containsSoftDelete();
        $this->tll                  = Carbon::now()->addHours(24);
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
        return collect(class_uses_recursive($this->entity))->contains(SoftDeletes::class);
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
        $this->limitValue      = null;
        $this->entity          = $this->resolveEntity();
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

        $paramsHash = ':' . md5(json_encode($queryState));
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
        // withoutCache() ativo: executa direto sem armazenar ou consultar o cache
        if ($this->bypassCache) {
            return $call();
        }

        $cacheKey = $this->getQualifyTagCache($method, $parameters);

        if ($this->supportsTags()) {
            return Cache::tags([$this->entity()])->remember($cacheKey, $this->tll, $call);
        }

        return Cache::remember($cacheKey, $this->tll, $call);
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

    public function find($id): static
    {
        $this->id = $id;
        return $this;
    }

    public function first()
    {
        if ($this->shouldUseView()) {
            $result = DB::table($this->activeView)->first();
            $this->resetScope();
            return collect($result);
        }

        $result = $this->rememberCache(function () {
            return $this->applyQueryScope($this->entity)->first();
        }, Repository::$methodFirst);

        $this->resetScope();
        return $result;
    }

    public function get()
    {
        if ($this->shouldUseView()) {
            $result = DB::table($this->activeView)->get();
            $this->resetScope();
            return collect($result);
        }

        $result = $this->rememberCache(function () {
            return $this->applyQueryScope($this->entity)->get();
        }, Repository::$methodAll);

        $this->resetScope();
        return $result;
    }

    public function findById($id)
    {
        $result = $this->rememberCache(function () use ($id) {
            return $this->applyQueryScope($this->entity)->find($id);
        }, Repository::$methodFind, [$id]);

        $this->resetScope();
        return $result;
    }

    public function findWhere(array $conditions)
    {
        $result = $this->rememberCache(function () use ($conditions) {
            $query = $this->applyQueryScope($this->entity);

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
            $query = $this->applyQueryScope($this->entity);

            foreach ($conditions as $filter) {
                $this->applyCustomFilter($query, $filter);
            }

            return $query->get();
        }, Repository::$methodFindWhereCustom, [$conditions]);

        $this->resetScope();
        return $result;
    }

    public function findWhereEmail($valor)
    {
        $result = $this->rememberCache(function () use ($valor) {
            return $this->applyQueryScope($this->entity)->where('email', $valor)->get();
        }, Repository::$methodFindWhereEmail, [$valor]);

        $this->resetScope();
        return $result;
    }

    public function findWhereFirst($column, $valor)
    {
        if ($this->shouldUseView()) {
            $result = DB::table($this->activeView)->where($column, $valor)->first();
            $this->resetScope();
            return collect($result);
        }

        $result = $this->rememberCache(function () use ($column, $valor) {
            return $this->applyQueryScope($this->entity)->where($column, $valor)->first();
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
        $result = $this->applyQueryScope($this->entity)->count();
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
        $result = $this->applyQueryScope($this->entity)->exists();
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
        $this->entity = $this->entity->latest($column);
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
        $this->entity = $this->entity->oldest($column);
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
        $this->entity = $this->entity->withCount($relations);
        return $this;
    }

    public function dataTable()
    {
        $result = $this->rememberCache(function () {
            return $this->applyQueryScope($this->entity)->get();
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
            return $this->applyQueryScope($this->entity)->orderBy($column, $order)->get();
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
        $request          = request();
        $perPage          = $request->get('pagesize', $totalPage);
        $search           = $request->get('search');
        $searchableFields = $request->get('searchable_fields', []);

        $query = $this->shouldUseView()
            ? DB::table($this->activeView)
            : $this->applyQueryScope($this->entity);

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

    // =========================================================================
    // ESCRITA
    // =========================================================================

    public function store(array $data)
    {
        $created = $this->entity->create($data);
        $this->clearCacheForEntity();
        return $created;
    }

    /**
     * Insere múltiplos registros em uma única operação (bulk insert).
     * Significativamente mais eficiente do que chamar store() em loop.
     *
     * @param bool $useEloquent  true → Eloquent (dispara eventos, mais lento)
     *                           false → insert() direto (mais rápido, sem eventos)
     *
     * Uso:
     *   $repository->storeMany([
     *       ['nome' => 'A', 'email' => 'a@a.com'],
     *       ['nome' => 'B', 'email' => 'b@b.com'],
     *   ]);
     */
    public function storeMany(array $records, bool $useEloquent = false): bool|array
    {
        if (empty($records)) {
            return false;
        }

        if ($useEloquent) {
            $created = [];
            foreach ($records as $data) {
                $created[] = $this->entity->create($data);
            }
            $this->clearCacheForEntity();
            return $created;
        }

        $now     = now();
        $records = array_map(fn($record) => array_merge(
            ['created_at' => $now, 'updated_at' => $now],
            $record
        ), $records);

        $result = $this->entity->insert($records);
        $this->clearCacheForEntity();

        return $result;
    }

    /**
     * Busca o model diretamente no banco (sem cache) antes de atualizar,
     * evitando atualizar um objeto stale retornado pelo cache.
     */
    public function update($id, array $data)
    {
        $model = $this->entity->newQuery()->find($id);

        if (!$model) {
            return false;
        }

        $updated = $model->update($data);
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
        $query = $this->entity->newQuery();

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
        $existing = $this->entity->newQuery()->find($id);

        if ($existing === null) {
            return $this->store($data);
        }

        return $this->update($id, $data);
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
        $result = $this->applyQueryScope($this->entity)->chunk($size, $callback);
        $this->resetScope();
        return $result;
    }

    // =========================================================================
    // EXCLUSÃO
    // =========================================================================

    public function delete(): bool
    {
        $model = $this->entity->find($this->id);

        if (!$model) return false;

        foreach ($this->relationships as $relationship) {
            $model->$relationship()->delete();
        }

        $deleted = $model->delete();
        $this->clearCacheForEntity();

        return (bool) $deleted;
    }

    public function restore(): bool
    {
        $model = $this->entity->withTrashed(true)->find($this->id);

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
        $model = $this->entity->withTrashed(true)->find($this->id);

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

        $this->entity = $this->entity->select($formattedColumns);

        return $this;
    }

    public function relationships(...$relationships): static
    {
        $this->relationships = [];

        foreach ($relationships as $relationship) {
            if ($this->hasContainsSoftDelete && $this->Trashed()) {
                $this->relationships[$relationship] = function ($query) {
                    $query->withTrashed();
                };
            } else {
                $this->relationships[] = $relationship;
            }
        }

        $this->entity = $this->entity->with($this->relationships);

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
        $query = $this->applyQueryScope($this->entity);

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
        $result = $this->applyQueryScope($this->entity)->sum($column);
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
        $result = $this->applyQueryScope($this->entity)->avg($column);
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
        $result = $this->applyQueryScope($this->entity)->min($column);
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
        $result = $this->applyQueryScope($this->entity)->max($column);
        $this->resetScope();
        return $result;
    }

    // =========================================================================
    // MATERIALIZED VIEWS
    // =========================================================================

    public function createMaterializedViews(): void
    {
        foreach ($this->registerViews() as $view => $query) {
            if (!$this->materializedViewExists($view)) {
                $this->createSingleMaterializedView($view, $query);
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

        foreach ($views as $v) {
            try {
                event(new BeforeRefreshMaterializedViewsJobEvent($v));

                $sql = $concurrently
                    ? "REFRESH MATERIALIZED VIEW CONCURRENTLY {$v};"
                    : "REFRESH MATERIALIZED VIEW {$v};";

                DB::statement($sql);

                DB::table('materialized_views')
                    ->where('name', $v)
                    ->update(['last_refreshed_at' => now()]);

                event(new AfterRefreshMaterializedViewsJobEvent($v));

            } catch (\Throwable $e) {
                Log::error("Erro ao refresh da materialized view [{$v}]: " . $e->getMessage());
            }
        }
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
        return $this;
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
