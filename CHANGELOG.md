# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), e este projeto segue o [Versionamento Semântico](https://semver.org/lang/pt-BR/) (SemVer).

## [4.1.0] - 2026-07-28

### Security
- **Busca textual sem whitelist agora desabilitada**: `resolveSearchableFields()` retorna `[]` quando nenhuma whitelist é detectada, em vez de aceitar todos os campos enviados pelo cliente. Impede oracle attack via ILIKE mesmo quando schema introspection falha.
- **Cache HTTP usa whitelist de headers seguros**: `cacheableHeaders()` agora só mantém `content-type`, `cache-control`, `pragma`, `expires`, `x-ratelimit-*`. Elimina risco de cache poisoning via headers arbitrários.
- **`EntityNotFoundException` não vaza entidade/ID**: mensagem de erro genérica `"Recurso não encontrado."` em vez de expor FQCN da entidade e ID buscado.
- **Removido `findById` do cache warming**: aquecer com `ID=1` hardcoded cacheava sentinela nula se o registro 1 não existisse, interferindo em `findOrFail` durante o TTL.

### Fixed
- **Race condition no `upsert()`**: agora executa dentro de `DB::transaction()` + captura `UniqueConstraintViolationException` com retry (mesma estratégia de `firstOrCreate`/`updateOrCreate`). Elimina duplicatas sob concorrência.
- **`storeMany()` sem transação**: envolto em `DB::transaction()` — se um registro falhar, os anteriores são revertidos.
- **`select()` com alias não sanitizado**: `$col` agora passa por `preg_replace` antes de ser usado como alias SQL.
- **Type confusion no `update()`**: comparação `!==` mudou para `(string) ... !== (string) ...` — evita falso positivo entre `1` e `"1"` que invalidava cache desnecessariamente.
- **`forceDelete()` não limpava relações em modelos não-trashed**: agora limpa relações (forceDelete) sempre que o model é encontrado, independente do estado `trashed()`.
- **`paginateWithView()` com chave de cache não-determinística**: `spl_object_hash()` removido da chave — cache agora funciona entre requests.
- **`serialize()` em chave de cache**: trocado para `json_encode()`, eliminando risco de object injection.
- **Log do ServiceProvider vazava `CACHE_STORE` e comando CLI**: removidos do contexto do warning.
- **`GenerateRepositoryCommand` expunha caminho absoluto**: mensagem de erro agora usa apenas o nome relativo.

### Added
- **`count()` e `exists()` agora usam cache**: resultados são cacheados com TTL padrão do repositório (via `rememberCache()`). Novas constantes `Repository::$methodCount` e `Repository::$methodExists`.
- **`dataTable(?int $limit = 5000)`**: parâmetro opcional `$limit` (padrão 5000) para evitar OOM em tabelas grandes. Passe `null` para unlimited.
- **Escopo de usuário no `cacheResponse`**: novo 3º parâmetro `auth` na definição da rota (`cacheResponse:600,tag,auth`) inclui `user_id` na chave de cache — impede que `/api/me` sirva dados de um usuário para outro.
- **Rastreamento de `withoutEvents()`**: logging em nível `debug` com o nome do repositório quando eventos são suprimidos.

### Changed
- `CacheApiResponse` agora aceita 3 parâmetros: `cacheResponse:{ttl?},{entityTag?},{scope?}`.

## [4.0.1] - 2026-07-19

### Performance
- **Introspecção de schema cacheada no fallback da busca**: `searchableWhitelist()` (usado pelo `paginate()` com `searchable_fields`) caía em `Schema::getColumnListing()` **sem cache** quando o repositório não declarava `$searchableColumns`/`$allowedColumns` — ~38ms por request de listagem paginada (consulta ao `pg_catalog`). Agora usa `tableColumns()`, que cacheia a introspecção por 24h. 1ª request paga; as demais são cache-hit.
- **`tableColumns()` agora é connection-aware**: usa `Schema::connection($this->getConnectionName())` e inclui a conexão na chave do cache. Antes usava a conexão default — o que falharia para models centrais (ex.: `tenants`) que vivem numa conexão diferente do default swapped por tenant. Também corrige `guardColumn`/`fuzzySearch`/`searchFullText`/`findWhereJson`, que validam colunas via `tableColumns()`.

## [3.2.0] - 2026-07-17

### Security
- **Busca textual do `paginate()` restrita a uma whitelist**: `searchable_fields` do request era usado diretamente como nome de coluna no `ILIKE`, permitindo ao cliente apontar a busca para qualquer coluna (oráculo de dados sensíveis, ex.: hash de senha, caractere a caractere) e sondar paths JSON arbitrários. Agora os campos são filtrados por `resolveSearchableFields()` contra `$searchableColumns` → `$allowedColumns` → colunas reais da tabela. Campos não declarados são descartados. Aplica-se a `paginate()` e `paginateWithView()`.
- **`CacheApiResponse` não cacheia mais cabeçalhos voláteis/sensíveis**: `Set-Cookie` (sessão/CSRF), `date` e `x-request-id` eram gravados no cache e reenviados a outros usuários — vazamento de sessão entre usuários numa resposta GET cacheada. Novo `cacheableHeaders()` remove esses cabeçalhos antes de armazenar.

### Added
- Nova property `$searchableColumns` no `BaseRepository`: declara as colunas liberadas para a busca textual do `paginate()` (coluna simples `'nome'` ou path JSON `'dados.cpf'`). Também é a fonte usada para criar os índices de busca.
- Comando `repository:search-indexes {repository?} {--apply} {--concurrently}`: cria índices **GIN `pg_trgm`** para as colunas de `$searchableColumns`, fazendo o `ILIKE '%x%'` do `paginate()` usar índice em vez de varrer a tabela. Sem `--apply` é dry-run (mostra o SQL); `--concurrently` cria sem lock de escrita (recomendado em produção). Garante `CREATE EXTENSION IF NOT EXISTS pg_trgm` uma vez por conexão e ignora conexões não-PostgreSQL.
- Métodos públicos de introspecção no `BaseRepository`: `declaredSearchableColumns()`, `getTable()`, `getConnectionName()`.
- **Store de cache dedicado** (Furo 2): nova config `repository.cache.store` (`REPOSITORY_CACHE_STORE`). O core e o `cacheResponse` passam a usar esse store para leitura, escrita e invalidação — independente do `cache.default` da app. Novos helpers estáticos `Repository::store()`, `Repository::storeSupportsTags()`, `Repository::cacheStoreName()`. O ServiceProvider emite warning no boot quando o store resolvido não suporta tags.
- **Trait `InvalidatesRepositoryCache`** (Furo 3): engancha os eventos Eloquent do model (`saved`/`deleted`/`restored`/`forceDeleted`) e invalida o cache da entidade (core + `cacheResponse`) em qualquer escrita Eloquent — passando ou não pelo repositório. Invalidação leve (só flush de tags, sem jobs de warming). Novo `Repository::flushEntity(string $modelClass)` para invalidação manual após escritas cruas (`DB::`, bulk update) que não disparam eventos Eloquent.

### Performance
- `rememberCache()` faz **uma única ida ao cache** (`get()` com sentinela) em vez de `has()` + `get()`, preservando o cache de valores nulos (ex.: `first()` sem registro). Passa a reusar a flag `$this->supportTag` (calculada no construtor) em vez de recomputar `supportsTags()` — que consultava o driver de cache — a cada leitura.
- `flushEntityCache()` invalida as tags da entidade (`entidade` + tag de api-response) em **um único `flush()`** em vez de dois, reduzindo idas ao driver por escrita.

### Changed
- **Invalidação do `cacheResponse` agora é escopada por entidade.** Antes, toda escrita flushava a tag global `api_response`, derrubando o cache HTTP de **todos** os endpoints a cada write (cache de resposta praticamente inútil sob carga de escrita). Agora `flushEntityCache()` limpa apenas as respostas marcadas com a tag da própria entidade — um write em `Client` não afeta o cache de `Product`/`Order`. Purge total continua possível manualmente via `Cache::tags(['api_response'])->flush()`.
- O middleware `cacheResponse` normaliza o `entityTag`: aceita `App\Models\Client` **ou** `App.Models.Client` (ambos casam com o flush do repositório).
- Cache do core, `cacheResponse` e `flushTags()` roteados pelo store dedicado (`Repository::store()`) em vez do `Cache` default — garante que leitura, escrita e invalidação usem o mesmo store. `supportsTags()` passa a resolver o driver do store configurado (via `repository.cache.unsupported_tag_drivers`), não mais o `Cache::getDefaultDriver()`.

### ⚠️ Compatibilidade
- **Rotas `cacheResponse` sem `entityTag` deixam de ser invalidadas por escrita** — passam a expirar só por TTL. Para manter invalidação imediata no write, declare o `entityTag` na rota: `cacheResponse:600,App\Models\Client`. (Antes o flush global `api_response` invalidava qualquer rota, ao custo de derrubar todo o cache HTTP a cada write.)
- Repositórios que buscam em **paths JSON** (`dados->>'cpf'`) precisam declarar a coluna base em `$searchableColumns` (ou `$allowedColumns`) — caso contrário esses campos são descartados da busca, pois não são colunas reais da tabela. Colunas simples já cobertas por `$allowedColumns`/pela tabela continuam funcionando sem mudança.

## [3.1.0]

### Added
- `withoutEvents()` — silencia todos os eventos do repositório na próxima operação (encadeável, resetado após a operação). Desliga os eventos de escrita (`RepositoryCreating/Created`, `RepositoryUpdating/Updated`, `RepositoryDeleting/Deleted`) e os de limpeza de cache; com os eventos de escrita silenciados, o veto de listeners (`shouldCreate`/`shouldUpdate`/`shouldDelete`) também não se aplica.
- Eventos `RepositoryBeforeClearingCacheEvent` / `RepositoryAfterClearingCacheEvent` passam a carregar o repositório (`$event->repository`) e são disparados por `clearCacheForEntity()`.
- `fireEvent()` — ponto único interno de disparo de eventos (respeita `withoutEvents()`).

### Fixed
- **Loop infinito** em `clearCacheForEntity()` quando um listener de `RepositoryBefore/AfterClearingCacheEvent` chamava `clearCacheForEntity()` novamente: adicionado guard de reentrância estático por entidade — a chamada reentrante apenas refaz o flush e retorna, sem re-disparar eventos nem re-agendar jobs. Trava liberada em `finally` (segura em workers de fila / Octane).
- `clearCacheForEntity()` instanciava `RepositoryBeforeClearingCacheEvent`/`RepositoryAfterClearingCacheEvent` sem o argumento `$repository` exigido pelo construtor (`ArgumentCountError`); agora passa `$this`.

## [3.0.0] - 2026-06-22

### ⚠️ Breaking Changes
- **Desacoplamento de tenancy**: removido o isolamento automático de views materializadas por `SharingPolicy`/`sub_tenant`. O package não depende mais de `risetechapps/tenancy-for-laravel`. O isolamento de views agora é feito sobrescrevendo o hook `applyViewScope()` no repositório (diretamente ou via trait). **Quem usava o filtro automático precisa migrar** — ver README, seção "Isolamento nas Views".

### Added
- `findOrFail($id)` — busca estrita que lança `EntityNotFoundException`.
- `transaction(callable, int $attempts)` — transação na conexão do model.
- `flushTags(array)` — invalidação granular de cache por tag.
- `resetMetrics()` — zera métricas estáticas (workers long-running).
- `flushEntityCache()` — ponto de extensão para invalidação granular (opt-in).
- Validação de nome de coluna (whitelist + identificador) em `fuzzySearch`, `searchFullText` e `findWhereJson` (proteção contra SQL injection); nova property `$allowedColumns`.
- Modo `strict` em `createMaterializedViews()`/`refreshMaterializedViews()` (falha-rápido nos comandos artisan).
- Generics no PHPDoc (`@template TModel`) e `@extends` no stub gerado.
- Suíte de testes (Pest + Orchestra Testbench).

### Fixed
- `cacheIf()` agora realmente condiciona o cache (antes era ignorado).
- `withCacheTags()`/`setTags()` agora criam tags reais de invalidação (antes só afetavam a chave).
- `RegenerateCacheJob` passou a tratar `FIRST` e `DATATABLE` (warming de `first()` nunca funcionava).
- Conexão do model respeitada em views materializadas, SQL raw e transações (antes usavam a conexão default).
- `registerViews()` ganhou default `[]` — opcional; corrige fatal em repositórios sem views.
- Jobs de cache marcados como `afterCommit` (não regeneram cache com dados revertidos).

### Changed
- `RefreshMaterializedViewsJob` só é despachado quando o repositório declara views.
- `warming_enabled`/`warming_methods` do config agora são respeitados.
- `comando` de geração documentado corretamente como `repository:make`.

## [2.6.0] - 2026-04-29
- Atualizado packages

## [2.5.0] - 2026-03-27
- Corrigido JOB RegenerateCacheJob

## [2.4.0] - 2026-03-21
- Implementado verificação se materialized existe antes de ser usado

## [2.3.0] - 2026-03-21
- Corrigido currentBuilder

## [2.2.0] - 2026-03-21
- Refatorado classe para ter carregamento posterior de inicialização de providers
- 
## [2.1.0] - 2026-03-16
- Criado novos eventos e corrigido query de refresh da materialized_view

## [2.0.0] - 2026-03-15
- Refatorado o código e aplicado novas funcionalidades.

## [1.9.0] - 2026-02-27
- Corrigido validação de Trashed e extendido o uso de activeView

## [1.8.0] - 2026-02-11
- Corrigido gerenciamento de cache

## [1.7.0] - 2026-02-06
- Corrigido $methodFindWhereCustom que estava fora do clean cache

## [1.6.0] - 2026-02-06
- Corrigido variável obsoleta

## [1.5.0] - 2026-02-04
- Atualizado versão do package predis/predis

## [1.4.0] - 2026-01-29
- Corrigido Log em registrar views
- Implementado suporte a view em findWhereFirst

## [1.3.0] - 2026-01-22
- Corrigido função Trashed, removido a verificação se é string ou bool
 
## [1.2.0] - 2026-01-21
- Corrigido validação de suporte as tags e de cacheamento

## [1.1.0] - 2025-12-08
### Added
- Adicionado comando para remover e aplicar novamente as materialized views.
 
## [1.0.0] - 2025-12-06
### Added
- Lançamento inicial (Primeira versão estável).
