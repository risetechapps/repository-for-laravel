# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), e este projeto segue o [Versionamento Semântico](https://semver.org/lang/pt-BR/) (SemVer).

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
