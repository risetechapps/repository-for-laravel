# Laravel Repository

## 📌 Sobre o Projeto

O **Laravel Repository** é um package para Laravel que abstrai a camada de dados, tornando a aplicação mais flexível e fácil de manter. Ele oferece cache automático com suporte a tags, soft deletes, materialized views (PostgreSQL), paginação dinâmica e um conjunto completo de métodos encadeáveis para consulta e manipulação de dados.

---

## ✨ Funcionalidades Principais

- 🗂️ **Cache Inteligente** - Cache automático com TTL configurável, tags e invalidação
- 🔄 **Soft Deletes** - `useTrashed()`, `onlyTrashed()`, `restore()` e `forceDelete()`
- 📊 **Materialized Views** - Suporte nativo a views materializadas do PostgreSQL
- 🔍 **Buscas Avançadas** - Filtros customizados, full-text, fuzzy search, JSONB
- 📦 **Operações em Lote** - `storeMany`, `updateMany`, `deleteMany`, `upsert`
- ⚡ **Performance** - Cursor pagination, selects otimizados, cache warming
- 🔔 **Eventos** - Eventos para create/update/delete com listeners configuráveis
- 🛡️ **Segurança** - Sanitização automática, validação de operadores, SQL injection protection
- 📈 **Métricas** - Query logging, cache hit rate, estatísticas de uso

---

## 📋 Novidades (v2.6.0)

### Novos Métodos
- `firstOrCreate()` / `updateOrCreate()` - Busca ou cria/atualiza
- `duplicate()` - Clona registros com modificações
- `increment()` / `decrement()` - Operações atômicas
- `whereDate()` / `whereIn()` / `whereBetween()` / `groupBy()` - Filtros avançados
- `view()` - Query Builder para Materialized Views
- `cacheFor()` / `cacheIf()` / `withCacheTags()` - Cache avançado
- `when()` / `selectOptimized()` / `cursorPaginate()` - Performance

### Melhorias
- Eventos do Repository (`RepositoryCreated`, `RepositoryUpdated`, etc.)
- Validação de operadores em `findWhereCustom`
- Cache automático em Materialized Views
- Serialização segura nos Jobs
- Configuração expandida em `config/repository.php`

---

## 🚀 Instalação

### Requisitos

- PHP >= 8.3
- Laravel >= 12
- PostgreSQL (para uso de Materialized Views)
- Composer instalado

### 1. Instalar o package

```bash
composer require risetechapps/repository-for-laravel
```

### 2. Publicar as configurações

```bash
php artisan vendor:publish --provider="RiseTechApps\Repository\RepositoryServiceProvider"
```

### 3. Criar um Repository

```bash
php artisan make:repository {name}
```

### 4. Configurar o Repository e a Interface

```php
// app/Repositories/ClientEloquentRepository.php
class ClientEloquentRepository extends BaseRepository implements ClientRepository
{
    public function entity(): string
    {
        return Client::class;
    }
}

// app/Repositories/Contracts/ClientRepository.php
interface ClientRepository extends RepositoryInterface
{
    // métodos customizados do domínio aqui
}
```

### 5. Definir colunas permitidas para ordenação (segurança)

```php
class ClientEloquentRepository extends BaseRepository implements ClientRepository
{
    // Apenas estas colunas são aceitas como sort_column no paginate()
    // Se omitido, o fallback é sempre 'id'
    protected array $allowedSortColumns = [
        'id', 'nome', 'email', 'created_at', 'status',
    ];

    public function entity(): string
    {
        return Client::class;
    }
}
```

---

## 📖 Referência de Métodos

### Leitura

---

#### `get()`
Retorna todos os registros do modelo.

```php
$clients = $clientRepository->get();
```

---

#### `first()`
Retorna o primeiro registro encontrado.

```php
$client = $clientRepository->first();
```

---

#### `findById($id)`
Busca um registro pelo ID.

```php
$client = $clientRepository->findById(1);
```

---

#### `findWhere(array $conditions)`
Filtra registros por condições simples de igualdade.

```php
$clients = $clientRepository->findWhere([
    'status' => 'ativo',
    'plano_id' => 3,
]);
```

---

#### `findWhereFirst($column, $value)`
Retorna o primeiro registro que corresponda ao filtro.

```php
$client = $clientRepository->findWhereFirst('email', 'joao@email.com');
```

---

#### `findWhereEmail($email)`
Atalho para buscar registros pelo campo `email`.

```php
$clients = $clientRepository->findWhereEmail('joao@email.com');
```

---

#### `findWhereCustom(array $conditions)`
Filtros avançados com suporte a operadores, grupos OR/AND, BETWEEN, IN, LIKE, IS NULL, etc.

```php
// Filtro simples com operador
$clients = $clientRepository->findWhereCustom([
    ['column' => 'status',     'operator' => '=',    'value' => 'ativo'],
    ['column' => 'created_at', 'operator' => '>=',   'value' => '2024-01-01'],
]);

// BETWEEN
$clientRepository->findWhereCustom([
    ['column' => 'total', 'operator' => 'BETWEEN', 'value' => [100, 500]],
]);

// IN
$clientRepository->findWhereCustom([
    ['column' => 'status', 'operator' => 'IN', 'value' => ['ativo', 'trial']],
]);

// LIKE
$clientRepository->findWhereCustom([
    ['column' => 'nome', 'operator' => 'LIKE', 'value' => 'João'],
]);

// IS NULL / IS NOT NULL
$clientRepository->findWhereCustom([
    ['column' => 'deleted_at', 'operator' => 'IS', 'value' => null],
]);

// Grupo OR
$clientRepository->findWhereCustom([
    ['orGroup' => [
        ['column' => 'status', 'operator' => '=', 'value' => 'ativo'],
        ['column' => 'status', 'operator' => '=', 'value' => 'trial'],
    ]],
]);

// Grupo AND dentro de OR
$clientRepository->findWhereCustom([
    ['andGroup' => [
        ['column' => 'plano_id', 'operator' => '=', 'value' => 2],
        ['column' => 'ativo',    'operator' => '=', 'value' => true],
    ]],
]);
```

---

#### `whereDate($column, $operator, $value)`
Filtra registros por data. Suporta operadores de comparação.

```php
// Registros criados em 2024
$clients = $clientRepository->whereDate('created_at', '>=', '2024-01-01')->get();

// Pedidos de hoje
$todayOrders = $orderRepository->whereDate('created_at', '=', now()->format('Y-m-d'))->get();

// Registros do mês passado
$lastMonth = $clientRepository->whereDate('created_at', '>=', now()->subMonth())->get();
```

---

#### `whereIn($column, array $values)`
Filtra registros onde a coluna está nos valores informados.

```php
// Status específicos
$clients = $clientRepository->whereIn('status', ['ativo', 'pendente'])->get();

// IDs específicos
$selected = $clientRepository->whereIn('id', [1, 2, 3, 4, 5])->get();

// Com encadeamento
$recentActive = $clientRepository
    ->whereIn('status', ['ativo', 'premium'])
    ->whereDate('created_at', '>=', now()->subDays(30))
    ->get();
```

---

#### `whereBetween($column, array $values)`
Filtra registros onde a coluna está entre dois valores.

```php
// Faixa de valores
$midRange = $orderRepository->whereBetween('valor', [100, 500])->get();

// Período de datas
$inPeriod = $clientRepository->whereBetween('created_at', [
    '2024-01-01',
    '2024-12-31'
])->get();

// Preço com desconto
$discounted = $productRepository->whereBetween('discount_percentage', [10, 50])->get();
```

---

#### `groupBy($columns)`
Agrupa resultados por coluna(s). Útil para consultas agregadas.

```php
// Agrupar por status
$byStatus = $clientRepository->select(['status', DB::raw('COUNT(*) as total')])
    ->groupBy('status')
    ->get();

// Agrupar por mês
$byMonth = $orderRepository
    ->select([
        DB::raw("DATE_TRUNC('month', created_at) as month"),
        DB::raw('SUM(valor) as total'),
        DB::raw('COUNT(*) as quantity')
    ])
    ->groupBy(DB::raw("DATE_TRUNC('month', created_at)"))
    ->get();
```

---

#### `count()`
Retorna o total de registros no escopo atual, sem carregar dados.

```php
$total = $clientRepository->count();

// Somente excluídos
$totalExcluidos = $clientRepository->onlyTrashed()->count();

// Incluindo excluídos
$totalGeral = $clientRepository->useTrashed(true)->count();
```

---

#### `exists()`
Verifica se existe ao menos um registro no escopo atual.

```php
if ($clientRepository->exists()) {
    // há registros
}

// Verificar se há excluídos
if ($clientRepository->onlyTrashed()->exists()) {
    // há registros deletados
}
```

---

#### `pluck(string $column, ?string $key = null)`
Retorna apenas os valores de uma coluna, sem carregar models completos.

```php
// Lista simples de nomes
$nomes = $clientRepository->pluck('nome');
// => Collection ['João', 'Maria', 'Carlos']

// Mapeado por ID (útil para selects e autocompletes)
$opcoes = $clientRepository->pluck('nome', 'id');
// => Collection [1 => 'João', 2 => 'Maria']

// Somente excluídos
$clientRepository->onlyTrashed()->pluck('email');
```

---

#### `sum(string $column)`
Retorna a soma dos valores de uma coluna numérica.

```php
$totalFaturado = $pedidoRepository->sum('total');

// Somente pedidos cancelados (excluídos)
$totalCancelado = $pedidoRepository->onlyTrashed()->sum('total');
```

---

#### `avg(string $column)`
Retorna a média dos valores de uma coluna numérica.

```php
$mediaNota = $avaliacaoRepository->avg('nota');

$mediaAtivos = $avaliacaoRepository->findWhere(['status' => 'publicado']);
// use avg() diretamente para médias por escopo
$mediaGeral = $avaliacaoRepository->avg('nota');
```

---

#### `min(string $column)`
Retorna o menor valor de uma coluna.

```php
$menorPreco = $produtoRepository->min('preco');

$primeiroCadastro = $clientRepository->min('created_at');
```

---

#### `max(string $column)`
Retorna o maior valor de uma coluna.

```php
$maiorPreco = $produtoRepository->max('preco');

$ultimoAcesso = $clientRepository->max('last_login_at');
```

---

#### `orderBy($column, $order = 'DESC')`
Retorna registros ordenados por uma coluna.

```php
$clientes = $clientRepository->orderBy('nome', 'ASC');

$recentes = $clientRepository->orderBy('created_at', 'DESC');
```

---

#### `dataTable()`
Retorna todos os registros para uso em tabelas (com cache).

```php
$dados = $clientRepository->dataTable();
```

---

### Modificadores encadeáveis

---

#### `latest(string $column = 'created_at')`
Ordena de forma descendente pela coluna informada. Encadeável com `get()`, `first()`, `limit()`, etc.

```php
$recentes = $clientRepository->latest()->get();

$ultimosAtualizados = $clientRepository->latest('updated_at')->limit(10)->get();
```

---

#### `oldest(string $column = 'created_at')`
Ordena de forma ascendente pela coluna informada.

```php
$primeiros = $clientRepository->oldest()->get();

$clientRepository->oldest('updated_at')->limit(5)->get();
```

---

#### `limit(int $value)`
Limita o número de registros retornados. Funciona com qualquer método terminal.

```php
$top10 = $clientRepository->limit(10)->get();

$ultimos5 = $clientRepository->latest()->limit(5)->get();

$excluidos = $clientRepository->onlyTrashed()->limit(3)->get();
```

---

#### `select(array $columns)`
Seleciona apenas as colunas informadas. Sempre inclui `id` automaticamente.
Suporta notação de JSON (`tabela.chave`) para campos JSONB no PostgreSQL.

```php
$clients = $clientRepository->select(['nome', 'email'])->get();

// JSON field (PostgreSQL)
$clients = $clientRepository->select(['meta.cidade', 'nome'])->get();
// gera: "meta"->>'cidade' as "meta.cidade"
```

---

#### `relationships(...$relationships)`
Carrega relacionamentos Eloquent junto com os registros.
Quando `useTrashed(true)` está ativo, os relacionamentos também incluem registros excluídos.

```php
$clients = $clientRepository->relationships('pedidos', 'enderecos')->get();

// Com soft deletes nos relacionamentos
$clients = $clientRepository
    ->useTrashed(true)
    ->relationships('pedidos', 'enderecos')
    ->get();
```

---

#### `withCount(string|array $relations)`
Adiciona a contagem de relacionamentos sem carregá-los. Disponível como `{relation}_count` em cada registro.

```php
$clients = $clientRepository->withCount('pedidos')->get();
// $client->pedidos_count

$clients = $clientRepository->withCount(['pedidos', 'enderecos'])->get();
// $client->pedidos_count, $client->enderecos_count
```

---

#### `withoutCache()`
Pula o cache para a próxima operação terminal, indo direto ao banco. O cache **não é invalidado** — apenas ignorado nessa chamada. Útil para contextos críticos como pós-pagamento ou relatórios em tempo real.

```php
$client = $clientRepository->withoutCache()->findById(1);

$clients = $clientRepository->withoutCache()->get();

$clientRepository->withoutCache()->paginate(20);
```

---

#### `setTags(array $tags)`
Define tags adicionais para segmentação do cache (somente drivers com suporte a tags).

```php
$clientRepository->setTags(['empresa:5'])->get();
```

---

#### `whereDate($column, $operator, $value)`
Filtra por data. Encadeável com outros métodos.

```php
$recent = $clientRepository->latest()->whereDate('created_at', '>=', '2024-01-01')->get();
```

---

#### `whereIn($column, array $values)`
Filtra por múltiplos valores. Encadeável.

```php
$selected = $clientRepository->whereIn('status', ['ativo', 'premium'])->limit(10)->get();
```

---

#### `whereBetween($column, array $values)`
Filtra por faixa de valores. Encadeável.

```php
$midRange = $orderRepository->whereBetween('valor', [100, 500])->get();
```

---

#### `groupBy($columns)`
Agrupa resultados. Encadeável com agregações.

```php
$summary = $repository->select(['status', DB::raw('COUNT(*) as total')])
    ->groupBy('status')
    ->get();
```

---

### Paginação

---

#### `paginate(int $totalPage = 10)`
Paginação dinâmica baseada em parâmetros do request. Protegida contra SQL Injection via `allowedSortColumns`.

**Parâmetros aceitos via request:**

| Parâmetro          | Descrição                                        |
|--------------------|--------------------------------------------------|
| `pagesize`         | Registros por página (padrão: `$totalPage`)      |
| `search`           | Texto para busca (`ILIKE`)                       |
| `searchable_fields`| Array de colunas onde a busca é aplicada         |
| `sort_column`      | Coluna de ordenação (validada contra whitelist)  |
| `sort_direction`   | `asc` ou `desc` (padrão: `asc`)                  |

```php
// No Controller
$result = $clientRepository->paginate(15);

// Com onlyTrashed
$result = $clientRepository->onlyTrashed()->paginate(10);

// Retorno
[
    'data'            => [...],   // registros da página atual
    'recordsFiltered' => 200,     // total filtrado
    'recordsTotal'    => 200,     // total geral
    'totalPages'      => 14,      // total de páginas
    'perPage'         => 15,      // registros por página
    'current_page'    => 1,       // página atual
]
```

---

### Soft Deletes

---

#### `useTrashed(bool $permission)`
Inclui registros soft-deleted nos resultados (equivalente ao `withTrashed` do Eloquent).

```php
// Todos os registros, incluindo excluídos
$todos = $clientRepository->useTrashed(true)->get();

// Somente ativos (comportamento padrão)
$ativos = $clientRepository->useTrashed(false)->get();
```

---

#### `onlyTrashed()`
Retorna **somente** os registros que foram soft-deleted (`deleted_at IS NOT NULL`).
Lança `RuntimeException` se o model não usar a trait `SoftDeletes`.

Compatível com: `get()`, `first()`, `findById()`, `findWhere()`, `findWhereCustom()`, `paginate()`, `count()`, `exists()`, `pluck()`, `sum()`, `avg()`, `min()`, `max()`, `chunk()`, `limit()`, `latest()`, `oldest()`.

```php
$excluidos = $clientRepository->onlyTrashed()->get();

$primeiro  = $clientRepository->onlyTrashed()->first();

$total     = $clientRepository->onlyTrashed()->count();

$pagina    = $clientRepository->onlyTrashed()->paginate(15);

$emails    = $clientRepository->onlyTrashed()->pluck('email');

$recentes  = $clientRepository->onlyTrashed()->latest('deleted_at')->limit(10)->get();

$clientRepository->onlyTrashed()->chunk(200, function ($lote) {
    foreach ($lote as $client) {
        // processar...
    }
});
```

---

### Escrita

---

#### `store(array $data)`
Cria um novo registro e invalida o cache.

```php
$client = $clientRepository->store([
    'nome'  => 'João Silva',
    'email' => 'joao@email.com',
    'plano_id' => 1,
]);
```

---

#### `storeMany(array $records, bool $useEloquent = false)`
Insere múltiplos registros em uma única operação. Muito mais eficiente do que chamar `store()` em loop.

- `$useEloquent = false` (padrão): usa `insert()` direto — mais rápido, sem eventos Eloquent, adiciona `created_at`/`updated_at` automaticamente.
- `$useEloquent = true`: usa `create()` — mais lento, mas dispara eventos e observers.

```php
// Insert direto (recomendado para grandes volumes)
$clientRepository->storeMany([
    ['nome' => 'Ana',  'email' => 'ana@email.com'],
    ['nome' => 'Bob',  'email' => 'bob@email.com'],
    ['nome' => 'Carl', 'email' => 'carl@email.com'],
]);

// Via Eloquent (dispara eventos e observers)
$clientRepository->storeMany([
    ['nome' => 'Ana', 'email' => 'ana@email.com'],
], useEloquent: true);
```

---

#### `update($id, array $data)`
Atualiza um registro pelo ID. Busca diretamente no banco (sem cache) para evitar atualizar dados desatualizados.

```php
$clientRepository->update(1, [
    'nome'  => 'João Atualizado',
    'plano_id' => 2,
]);
```

---

#### `updateMany(array $data, array $conditions)`
Atualiza múltiplos registros por condições. Executa uma única query `UPDATE ... WHERE`, sem carregar models em memória. Retorna o número de registros afetados.

```php
// Inativar todos de um plano
$afetados = $clientRepository->updateMany(
    ['status' => 'inativo'],
    ['plano_id' => 3]
);

// Múltiplas condições
$clientRepository->updateMany(
    ['ativo' => false],
    ['empresa_id' => 10, 'tipo' => 'free']
);
```

---

#### `createOrUpdate($id, array $data)`
Cria um novo registro se o ID não existir, ou atualiza se existir. A verificação de existência é feita diretamente no banco (sem cache).

```php
$clientRepository->createOrUpdate(1, ['nome' => 'João']);   // atualiza
$clientRepository->createOrUpdate(99, ['nome' => 'Maria']); // cria
```

---

#### `firstOrCreate(array $attributes, array $values = [])`
Retorna o primeiro registro que corresponda aos atributos, ou cria um novo.

```php
// Busca por email, cria se não existir
$client = $clientRepository->firstOrCreate(
    ['email' => 'joao@email.com'],
    ['nome' => 'João', 'telefone' => '1199999999']
);

// Equivalente a:
// $client = Client::where('email', 'joao@email.com')->first() ?? Client::create([...])
```

---

#### `updateOrCreate(array $attributes, array $values = [])`
Atualiza um registro existente ou cria um novo.

```php
// Atualiza se email existe, senão cria
$client = $clientRepository->updateOrCreate(
    ['email' => 'joao@email.com'],
    ['nome' => 'João Silva', 'telefone' => '11988888888']
);

// Equivalente a:
// $client = Client::updateOrCreate(['email' => ...], ['nome' => ...])
```

---

#### `duplicate($id, array $modifications = [])`
Duplica um registro existente com modificações opcionais.

```php
// Duplica o cliente 1
$newClient = $clientRepository->duplicate(1);

// Duplica com modificações
$newClient = $clientRepository->duplicate(1, [
    'nome' => 'Cópia do Cliente',
    'email' => 'copia@email.com'
]);

// IDs e timestamps são automaticamente removidos
```

---

#### `increment($id, $column, $amount = 1)`
Incrementa uma coluna numericamente (operação atômica).

```php
// +1 na coluna visitas
$clientRepository->increment(1, 'visitas');

// +5 na coluna pontos
$clientRepository->increment(1, 'pontos', 5);

// Útil para contadores: views, likes, downloads
$productRepository->increment($productId, 'view_count');
```

---

#### `decrement($id, $column, $amount = 1)`
Decrementa uma coluna numericamente (operação atômica).

```php
// -1 no estoque
$productRepository->decrement(1, 'stock');

// -5 no estoque
$productRepository->decrement(1, 'stock', 5);

// Útil para controle de estoque
if ($productRepository->decrement($id, 'quantity', $amount)) {
    // Estoque decrementado com sucesso
} else {
    // Produto não encontrado
}
```

---

#### `chunk(int $size, callable $callback)`
Processa grandes volumes de registros em lotes para evitar estouro de memória. Compatível com `onlyTrashed()` e `useTrashed()`.

```php
// Processar em lotes de 500
$clientRepository->chunk(500, function ($clientes) {
    foreach ($clientes as $cliente) {
        // processar cada cliente...
    }
});

// Processar somente excluídos em lotes
$clientRepository->onlyTrashed()->chunk(200, function ($excluidos) {
    foreach ($excluidos as $cliente) {
        // reprocessar ou auditar...
    }
});
```

---

### Exclusão

---

#### `find($id)` + `delete()`
Soft-delete de um registro e seus relacionamentos configurados.

```php
$clientRepository->find(1)->delete();
```

---

#### `find($id)` + `restore()`
Restaura um registro soft-deleted e seus relacionamentos.

```php
$clientRepository->find(1)->restore();
```

---

#### `find($id)` + `forceDelete()`
Remove permanentemente um registro soft-deleted. Só funciona se o registro já estiver na lixeira.

```php
$clientRepository->find(1)->forceDelete();
```

---

### Materialized Views (PostgreSQL)

Permitem pré-calcular e cachear consultas complexas diretamente no banco, com refresh controlado pela aplicação.

---

#### `registerViews()` — configuração na subclasse

```php
class RelatorioPedidoRepository extends BaseRepository
{
    public function entity(): string
    {
        return Pedido::class;
    }

    protected function registerViews(): array
    {
        return [
            'vw_pedidos_resumo' => "
                SELECT cliente_id, COUNT(*) as total_pedidos, SUM(valor) as faturamento
                FROM pedidos
                WHERE deleted_at IS NULL
                GROUP BY cliente_id
            ",
        ];
    }
}
```

---

#### `useMaterializedView(string $view)`
Direciona as próximas queries para a view materializada em vez da tabela principal.
Bloqueada automaticamente quando `onlyTrashed()` ou `useTrashed(true)` está ativo.

```php
$resumo = $relatorioRepository
    ->useMaterializedView('vw_pedidos_resumo')
    ->get();

$item = $relatorioRepository
    ->useMaterializedView('vw_pedidos_resumo')
    ->findWhereFirst('cliente_id', 5);
```

---

#### `createMaterializedViews()`
Cria todas as views registradas em `registerViews()` caso ainda não existam no banco.

```php
$relatorioRepository->createMaterializedViews();
```

---

#### `refreshMaterializedViews(?string $view = null, bool $concurrently = true)`
Atualiza os dados das views. Por padrão usa `CONCURRENTLY` para não bloquear leituras.
Dispara `BeforeRefreshMaterializedViewsJobEvent` antes e `AfterRefreshMaterializedViewsJobEvent` depois.

```php
// Refresh de todas as views registradas
$relatorioRepository->refreshMaterializedViews();

// Refresh de uma view específica
$relatorioRepository->refreshMaterializedViews('vw_pedidos_resumo');

// Sem CONCURRENTLY (necessário na primeira vez, antes de criar índice único)
$relatorioRepository->refreshMaterializedViews(concurrently: false);
```

---

#### `cleanMaterializedView()`
Remove todas as views materializadas registradas.

```php
$relatorioRepository->cleanMaterializedView();
```

---

## 🔗 Encadeamento

Os métodos encadeáveis podem ser combinados livremente. O escopo é sempre resetado automaticamente após a operação terminal, evitando vazamento de estado entre chamadas.

```php
// Últimos 10 clientes excluídos, com contagem de pedidos
$clientRepository
    ->onlyTrashed()
    ->withCount('pedidos')
    ->latest('deleted_at')
    ->limit(10)
    ->get();

// Relatório sem cache com relacionamentos
$clientRepository
    ->withoutCache()
    ->relationships('enderecos', 'pedidos')
    ->select(['id', 'nome', 'email'])
    ->paginate(25);

// Busca avançada com filtros customizados
$clientRepository
    ->useTrashed(true)
    ->findWhereCustom([
        ['column' => 'plano_id', 'operator' => 'IN',      'value' => [1, 2, 3]],
        ['column' => 'created_at','operator' => 'BETWEEN', 'value' => ['2024-01-01', '2024-12-31']],
    ]);
```

---

## 🛠 Contribuição

Sinta-se à vontade para contribuir! Basta seguir estes passos:

1. Faça um fork do repositório
2. Crie uma branch (`feature/nova-funcionalidade`)
3. Faça um commit das suas alterações
4. Envie um Pull Request

---

## 📜 Licença

Este projeto é distribuído sob a licença MIT. Veja o arquivo [LICENSE](LICENSE) para mais detalhes.

---

---

## 📊 Materialized Views (PostgreSQL)

As Materialized Views permitem pré-calcular e cachear consultas complexas diretamente no PostgreSQL, com refresh controlado pela aplicação e cache adicional na camada de aplicação.

### ⚙️ Requisitos

- **PostgreSQL** 12+ (para suporte completo a Materialized Views)
- Extensão `pg_trgm` para fuzzy search (opcional)
- Driver de cache que suporte **tags** (Redis ou Memcached) recomendado

### 📝 Exemplo Completo

#### 1. Definindo a View no Repository

**Forma Recomendada (Query Builder):**

```php
class RelatorioVendasRepository extends BaseRepository
{
    public function entity(): string
    {
        return Pedido::class;
    }

    /**
     * Registra as views materializadas usando Query Builder.
     * Mais seguro, com autocomplete do IDE e type safety.
     */
    protected function registerViews(): array
    {
        return [
            // Usando Query Builder ✅
            $this->view('vw_vendas_por_cliente', function ($query) {
                return $query->select([
                        'cliente_id',
                        DB::raw('COUNT(*) as total_pedidos'),
                        DB::raw('SUM(valor) as faturamento_total'),
                        DB::raw('MIN(valor) as menor_pedido'),
                        DB::raw('MAX(valor) as maior_pedido'),
                        DB::raw('AVG(valor) as ticket_medio'),
                    ])
                    ->whereNull('deleted_at')
                    ->groupBy('cliente_id');
            }),

            // Com joins
            $this->view('vw_pedidos_com_cliente', function ($query) {
                return $query
                    ->select([
                        'pedidos.*',
                        'clientes.nome as cliente_nome',
                        'clientes.email as cliente_email',
                    ])
                    ->join('clientes', 'pedidos.cliente_id', '=', 'clientes.id')
                    ->whereNull('pedidos.deleted_at');
            }),

            // Também suporta SQL string (legado) ⚠️
            // 'vw_outra_view' => 'SELECT * FROM pedidos WHERE status = \'ativo\'',
        ];
    }
}
```

**Vantagens do Query Builder:**
- ✅ **Autocompleto** do IDE para colunas e métodos
- ✅ **Type safety** - Erros detectados em tempo de compilação
- ✅ **Escapamento automático** - Proteção contra SQL injection
- ✅ **Fácil manutenção** - Refatoração segura
- ✅ **Portabilidade** - Funciona com diferentes drivers de banco
```

#### 2. Usando a View Materializada

```php
$repository = app(RelatorioVendasRepository::class);

// Cria a view automaticamente se não existir
$repository->createMaterializedViews();

// Usa a view para consultas (cacheado)
$vendasPorCliente = $repository
    ->useMaterializedView('vw_vendas_por_cliente')
    ->get();

// Busca específica na view
$cliente = $repository
    ->useMaterializedView('vw_vendas_por_cliente')
    ->findWhereFirst('cliente_id', 123);

// Paginação com cache
$paginado = $repository
    ->useMaterializedView('vw_vendas_por_cliente')
    ->orderBy('faturamento_total', 'DESC')
    ->paginate(20);
```

#### 3. Atualizando a View (Refresh)

```php
// Refresh de todas as views registradas
$repository->refreshMaterializedViews();

// Refresh de uma view específica
$repository->refreshMaterializedViews('vw_vendas_por_cliente');

// Refresh sem CONCURRENTLY (útil na primeira vez ou sem índice único)
$repository->refreshMaterializedViews(concurrently: false);
```

#### 4. Schedule Automático

Adicione ao `routes/console.php` ou `App\Console\Kernel.php`:

```php
use Illuminate\Support\Facades\Schedule;
use App\Repositories\RelatorioVendasRepository;

// Atualiza a view a cada hora
Schedule::call(function () {
    app(RelatorioVendasRepository::class)->refreshMaterializedViews();
})->hourly();

// Ou use o comando Artisan
Schedule::command('repository:refresh-materialized-views RelatorioVendas')->hourly();
```

#### 5. Comando Artisan

```bash
# Cria as views se não existirem
php artisan repository:create-materialized-views RelatorioVendasRepository

# Atualiza as views
php artisan repository:refresh-materialized-views RelatorioVendasRepository

# Remove e recria as views
php artisan repository:restart-materialized-views RelatorioVendasRepository
```

### 🔄 Eventos de Refresh

O package dispara eventos durante o refresh:

```php
// Antes de atualizar qualquer view
Event::listen(\RiseTechApps\Repository\Events\BeforeRefreshAllMaterializedViewsJobEvent::class, function () {
    Log::info('Iniciando refresh de todas as views...');
});

// Antes de cada view
Event::listen(\RiseTechApps\Repository\Events\BeforeRefreshMaterializedViewsJobEvent::class, function ($event) {
    Log::info("Atualizando view: {$event->viewName}");
});

// Depois de cada view
Event::listen(\RiseTechApps\Repository\Events\AfterRefreshMaterializedViewsJobEvent::class, function ($event) {
    Log::info("View atualizada: {$event->viewName}");
});

// Depois de todas
Event::listen(\RiseTechApps\Repository\Events\AfterRefreshAllMaterializedViewsJobEvent::class, function () {
    Log::info('Todas as views foram atualizadas');
});
```

### 📈 Performance

**Sem Materialized View:**
```
Query: SELECT ... GROUP BY cliente_id (tabela com 1M registros)
Tempo: ~500ms a cada consulta
```

**Com Materialized View:**
```
Primeira consulta: ~500ms (pré-calculada no banco)
Consultas subsequentes: ~5ms (cache da aplicação)
Speedup: 100x
```

### ⚠️ Limitações

1. **Não funciona com soft deletes**: Views materializadas não incluem registros excluídos (`deleted_at IS NOT NULL`).
   ```php
   // ❌ Não funciona
   $repository->onlyTrashed()->useMaterializedView('vw_xxx')->get();
   
   // ✅ Usa a tabela normal
   $repository->onlyTrashed()->get();
   ```

2. **Dados podem estar desatualizados**: O refresh é manual ou agendado.

3. **Requer PostgreSQL**: MySQL não suporta Materialized Views nativamente.

4. **Cache**: Recomenda-se usar Redis/Memcached para melhor performance com tags.

### 🏢 Suporte a Multi-Tenancy

As views automaticamente aplicam o filtro de sub_tenant baseado na `SharingPolicy` do model:

```php
// Model Pedido
class Pedido extends Model
{
    use HasSharingPolicy;
    
    public function sharingPolicy(): SharingPolicy
    {
        return SharingPolicy::RESTRICTED; // ou USER_FILIALS, ALL_FILIALS
    }
}

// A view vw_vendas_por_cliente será automaticamente filtrada
// pelo sub_tenant_id do contexto atual
```

---

💡 **Desenvolvido por [Rise Tech](https://risetech.com.br)**
