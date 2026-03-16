# Changelog

Todas as alterações notáveis neste projeto serão documentadas neste arquivo.
O formato é baseado em [Keep a Changelog](https://keepachangelog.com/en/1.0.0/), e este projeto segue o [Versionamento Semântico](https://semver.org/lang/pt-BR/) (SemVer).

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
