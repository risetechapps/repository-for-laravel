# Mensagens de Commit - Repository for Laravel

Arquivo com sugestões de mensagens de commit e arquivos modificados para cada alteração.

---

## � Correções de Bugs

### 1. Cache em get() com Materialized Views
**Arquivos:** `src/Core/BaseRepository.php`

```bash
git add src/Core/BaseRepository.php
git commit -m "fix: corrigido cache em get() com materialized views

Agora o método get() utiliza cache quando useMaterializedView() está ativo.
Anteriormente consultava direto no banco a cada chamada."
```

---

### 2. Cache em findWhereFirst() com Materialized Views
**Arquivos:** `src/Core/BaseRepository.php`

```bash
git add src/Core/BaseRepository.php
git commit -m "fix: corrigido cache em findWhereFirst() com materialized views

findWhereFirst() agora usa cache para views materializadas.
Removido wrap em Collection desnecessário."
```

---

### 3. Cache em paginate() com Materialized Views
**Arquivos:** `src/Core/BaseRepository.php`, `src/Repository.php`

```bash
git add src/Core/BaseRepository.php src/Repository.php
git commit -m "fix: corrigido cache em paginate() com materialized views

paginate() agora utiliza cache quando views materializadas estão ativas.
Adicionado método paginateWithView() separado para tratamento adequado.
Adicionado métodoPaginate em Repository.php"
```

---

### 4. Serialização de Repository nos Jobs
**Arquivos:** `src/Jobs/RefreshMaterializedViewsJob.php`, `src/Jobs/RegenerateCacheJob.php`

```bash
git add src/Jobs/RefreshMaterializedViewsJob.php src/Jobs/RegenerateCacheJob.php
git commit -m "fix: corrigido serialização de Repository nos Jobs

Jobs agora armazenam classe do repository (string) em vez do objeto completo.
Evita problemas de serialização e garante estado fresh.
Adicionado use strict_types em RegenerateCacheJob.php"
```

---

### 5. Chamada find() em RegenerateCacheJob
**Arquivos:** `src/Jobs/RegenerateCacheJob.php`

```bash
git add src/Jobs/RegenerateCacheJob.php
git commit -m "fix: corrigido chamada find() em RegenerateCacheJob

Alterado de find() para findById() no job de regeneração de cache.
find() retorna o repository, não o model."
```

---

### 6. Vazamento de Autenticação em RefreshMaterializedViewsJob
**Arquivos:** `src/Jobs/RefreshMaterializedViewsJob.php`

```bash
git add src/Jobs/RefreshMaterializedViewsJob.php
git commit -m "fix: corrigido vazamento de autenticação em RefreshMaterializedViewsJob

Adicionado try/finally para restaurar estado de auth após execução.
Guarda usuário anterior e restaura no finally.
Evita que usuário persista entre jobs no worker de queue."
```

---

### 7. newQuery()->newQuery() Redundante
**Arquivos:** `src/Core/BaseRepository.php`

```bash
git add src/Core/BaseRepository.php
git commit -m "fix: corrigido newQuery()->newQuery() redundante em update()

Removida chamada duplicada de newQuery() no método update()."
```

---

### 8. Validação de Operadores
**Arquivos:** `src/Core/BaseRepository.php`, `src/Exception/InvalidFilterException.php`

```bash
git add src/Core/BaseRepository.php src/Exception/InvalidFilterException.php
git commit -m "fix: implementada validação de operadores em findWhereCustom()

Adicionada propriedade $allowedOperators no BaseRepository.
Valida operadores antes de aplicar filtros.
Lança InvalidFilterException para operadores não permitidos."
```

---

## ✨ Novas Funcionalidades

### 9. Método view() para Materialized Views
**Arquivos:** `src/Core/BaseRepository.php`, `src/Contracts/RepositoryInterface.php`

```bash
git add src/Core/BaseRepository.php src/Contracts/RepositoryInterface.php
git commit -m "feat: adicionado método view() para Materialized Views com Query Builder

Novo método view($name, callable) permite definir views usando Query Builder.
Mais seguro que SQL string, com autocomplete e type safety.
Método suporta tanto Query Builder quanto SQL string (legado)."
```

---

### 10. firstOrCreate() e updateOrCreate()
**Arquivos:** `src/Core/BaseRepository.php`, `src/Contracts/RepositoryInterface.php`

```bash
git add src/Core/BaseRepository.php src/Contracts/RepositoryInterface.php
git commit -m "feat: adicionados métodos firstOrCreate() e updateOrCreate()

firstOrCreate($attributes, $values) - Busca ou cria registro.
updateOrCreate($attributes, $values) - Atualiza ou cria registro.
Padrão Laravel, compatível com cache do package."
```

---

### 11. Método duplicate()
**Arquivos:** `src/Core/BaseRepository.php`, `src/Contracts/RepositoryInterface.php`

```bash
git add src/Core/BaseRepository.php src/Contracts/RepositoryInterface.php
git commit -m "feat: adicionado método duplicate() para clonar registros

duplicate($id, $modifications) - Clona registro com modificações opcionais.
Remove automaticamente id, timestamps e deleted_at.
Útil para criar templates ou cópias."
```

---

### 12. increment() e decrement()
**Arquivos:** `src/Core/BaseRepository.php`, `src/Contracts/RepositoryInterface.php`

```bash
git add src/Core/BaseRepository.php src/Contracts/RepositoryInterface.php
git commit -m "feat: adicionados métodos increment() e decrement()

increment($id, $column, $amount) - Incrementa coluna atomicamente.
decrement($id, $column, $amount) - Decrementa coluna atomicamente.
Operações thread-safe para contadores e estoque.
Limpam cache automaticamente após operação."
```

---

### 13. Filtros Avançados (whereDate, whereIn, etc)
**Arquivos:** `src/Core/BaseRepository.php`, `src/Contracts/RepositoryInterface.php`

```bash
git add src/Core/BaseRepository.php src/Contracts/RepositoryInterface.php
git commit -m "feat: adicionados métodos de filtro avançado

whereDate($column, $operator, $value) - Filtro por data.
whereIn($column, $values) - Filtro por múltiplos valores.
whereBetween($column, $values) - Filtro por faixa.
groupBy($columns) - Agrupamento de resultados.
Todos encadeáveis e com suporte a cache."
```

---

### 14. Cache Avançado
**Arquivos:** `src/Core/BaseRepository.php`, `src/Contracts/RepositoryInterface.php`

```bash
git add src/Core/BaseRepository.php src/Contracts/RepositoryInterface.php
git commit -m "feat: adicionados métodos de cache avançado

cacheFor($minutes) - Define TTL customizado.
cacheForHours($hours) - Define TTL em horas.
cacheForDays($days) - Define TTL em dias.
withCacheTags($tags) - Tags hierárquicas para cache.
cacheIf($condition) - Cache condicional."
```

---

### 15. Performance (when, selectOptimized, cursorPaginate)
**Arquivos:** `src/Core/BaseRepository.php`, `src/Contracts/RepositoryInterface.php`

```bash
git add src/Core/BaseRepository.php src/Contracts/RepositoryInterface.php
git commit -m "feat: adicionados métodos de performance

when($condition, $callback) - Lazy loading condicional.
selectOptimized($columns) - Seleção de colunas otimizada.
cursorPaginate($perPage) - Cursor pagination para grandes datasets."
```

---

### 16. Eventos do Repository
**Arquivos:** 
- `src/Events/RepositoryEvent.php`
- `src/Events/RepositoryCreating.php`
- `src/Events/RepositoryCreated.php`
- `src/Events/RepositoryUpdating.php`
- `src/Events/RepositoryUpdated.php`
- `src/Events/RepositoryDeleting.php`
- `src/Events/RepositoryDeleted.php`
- `src/Core/BaseRepository.php`
- `config/config.php`

```bash
git add src/Events/Repository*.php src/Core/BaseRepository.php config/config.php
git commit -m "feat: adicionado suporte a eventos do Repository

Eventos: RepositoryCreating, RepositoryCreated, RepositoryUpdating,
       RepositoryUpdated, RepositoryDeleting, RepositoryDeleted.
Disparados automaticamente nas operações CRUD.
Configuráveis em config/repository.php."
```

---

### 17. Sanitização Automática
**Arquivos:** `src/Core/BaseRepository.php`, `config/config.php`

```bash
git add src/Core/BaseRepository.php config/config.php
git commit -m "feat: adicionada sanitização automática de inputs

Remove tags HTML de inputs antes de store/update.
Configurável em config/repository.php.
Proteção contra XSS."
```

---

### 18. Exceções Específicas
**Arquivos:**
- `src/Exception/RepositoryException.php`
- `src/Exception/EntityNotFoundException.php`
- `src/Exception/InvalidFilterException.php`
- `src/Exception/CacheOperationException.php`
- `src/Exception/MaterializedViewException.php`
- `src/Exception/NotEntityDefinedException.php` (atualizado)

```bash
git add src/Exception/
git commit -m "feat: adicionado suporte a exceções específicas

RepositoryException - Classe base.
EntityNotFoundException - Entidade não encontrada.
InvalidFilterException - Filtro inválido.
CacheOperationException - Erro de cache.
MaterializedViewException - Erro de view materializada.
NotEntityDefinedException atualizado com mensagens."
```

---

### 19. Comando Artisan Warm Cache
**Arquivos:** 
- `src/Commands/RepositoryWarmCacheCommand.php`
- `src/RepositoryServiceProvider.php`

```bash
git add src/Commands/RepositoryWarmCacheCommand.php src/RepositoryServiceProvider.php
git commit -m "feat: adicionado comando artisan repository:warm-cache

Comando para pré-aquecer cache de repositories.
Útil para manter dados frequentemente acessados em cache.
Registrado em RepositoryServiceProvider."
```

---

### 20. Cache Warming Automático
**Arquivos:** `src/Core/BaseRepository.php`, `src/Contracts/RepositoryInterface.php`

```bash
git add src/Core/BaseRepository.php src/Contracts/RepositoryInterface.php
git commit -m "feat: adicionado método warmCache() para cache warming

warmCache($methods) - Executa métodos para aquecer cache.
Suporta: get, first, findById, dataTable."
```

---

### 21. Métricas do Repository
**Arquivos:** `src/Core/BaseRepository.php`, `src/Contracts/RepositoryInterface.php`

```bash
git add src/Core/BaseRepository.php src/Contracts/RepositoryInterface.php
git commit -m "feat: adicionadas métricas e query logging

enableSlowQueryLog($threshold) - Log de queries lentas.
getMetrics() - Retorna estatísticas de uso.
trackQueryMetrics() - Tracking interno de queries.
Métricas: total_queries, cache_hit_rate, avg_query_time."
```

---

## 🔧 Melhorias

### 22. Documentação do Método find()
**Arquivos:** `src/Core/BaseRepository.php`

```bash
git add src/Core/BaseRepository.php
git commit -m "refactor: documentado comportamento do método find()

Adicionada documentação clara que find() retorna $this (repository).
find() é para encadeamento com delete/restore, não para buscar model.
Para buscar model, usar findById().
Adicionado método setId() como alternativa mais explícita."
```

---

### 23. storeMany() Retorno Consistente
**Arquivos:** `src/Core/BaseRepository.php`, `src/Contracts/RepositoryInterface.php`

```bash
git add src/Core/BaseRepository.php src/Contracts/RepositoryInterface.php
git commit -m "refactor: melhorado retorno de storeMany()

Agora retorna sempre array de models (consistente).
Removido parâmetro $useEloquent (sempre usa Eloquent).
Simplificação da API."
```

---

### 24. JSON Cache Keys Ordenadas
**Arquivos:** `src/Core/BaseRepository.php`

```bash
git add src/Core/BaseRepository.php
git commit -m "refactor: garantida ordem consistente em cache keys

Adicionado JSON_SORTED_KEYS em json_encode para cache keys.
Evita colisões quando arrays têm mesmas chaves em ordem diferente."
```

---

## 📚 Documentação

### 25. README.md Atualizado
**Arquivos:** `README.md`

```bash
git add README.md
git commit -m "docs: atualizado README com novas funcionalidades

Adicionada seção 'Funcionalidades Principais'.
Adicionada seção 'Novidades (v2.6.0)'.
Documentados todos os novos métodos com exemplos.
Melhorada documentação de Materialized Views.
Adicionados exemplos de Query Builder para views."
```

---

### 26. Arquivo de Mensagens de Commit
**Arquivos:** `COMMIT_MESSAGES.md`

```bash
git add COMMIT_MESSAGES.md
git commit -m "docs: adicionado arquivo de mensagens de commit

Arquivo com sugestões de mensagens organizadas por tipo.
Inclui arquivos modificados para cada commit.
Facilita criação de commits semânticos."
```

---

## 📝 Commit Final Consolidado (Opcional)

Para um único commit com todas as mudanças:

```bash
# Adicionar todos os arquivos modificados
git add src/ config/ README.md COMMIT_MESSAGES.md

# Commit consolidado
git commit -m "feat: versão 2.6.0 - novas funcionalidades e correções

## ✨ Novas Funcionalidades
- firstOrCreate() / updateOrCreate() - Busca ou cria/atualiza
- duplicate() - Clona registros com modificações
- increment() / decrement() - Operações atômicas
- whereDate() / whereIn() / whereBetween() / groupBy() - Filtros
- view() - Query Builder para Materialized Views
- cacheFor() / cacheIf() / withCacheTags() - Cache avançado
- when() / selectOptimized() / cursorPaginate() - Performance
- Eventos do Repository (6 eventos)
- Sanitização automática de inputs
- Exceções específicas do package
- Comando artisan repository:warm-cache
- Métricas e query logging

## � Correções
- Cache em get(), findWhereFirst(), paginate() com views
- Serialização segura nos Jobs
- Vazamento de auth em RefreshMaterializedViewsJob
- Validação de operadores em findWhereCustom()
- newQuery() duplicado em update()

## 🔧 Melhorias
- Documentação do método find()
- Retorno consistente em storeMany()
- JSON cache keys ordenadas
- Interface RepositoryInterface atualizada

## 📚 Documentação
- README.md atualizado
- COMMIT_MESSAGES.md criado

BREAKING CHANGE: storeMany() agora sempre retorna array

Arquivos: src/, config/, README.md, COMMIT_MESSAGES.md"
```

---

## 🚀 Checklist para Release

Antes de publicar a versão 2.6.0:

- [ ] Testar todos os novos métodos
- [ ] Verificar compatibilidade com Laravel 12
- [ ] Atualizar CHANGELOG.md
- [ ] Criar tag git: `git tag -a v2.6.0 -m "Versão 2.6.0"`
- [ ] Push para GitHub: `git push origin main --tags`
- [ ] Criar release no GitHub

---

## 🗂️ Resumo por Diretório

| Diretório | Arquivos | Tipo |
|-----------|----------|------|
| `src/Core/` | `BaseRepository.php` | Core |
| `src/Contracts/` | `RepositoryInterface.php` | Interface |
| `src/Commands/` | `RepositoryWarmCacheCommand.php` | Comandos |
| `src/Events/` | `Repository*.php` (7 arquivos) | Eventos |
| `src/Exception/` | `*.php` (6 arquivos) | Exceções |
| `src/Jobs/` | `RefreshMaterializedViewsJob.php`, `RegenerateCacheJob.php` | Jobs |
| `src/` | `Repository.php`, `RepositoryServiceProvider.php` | Config |
| `config/` | `config.php` | Configuração |
| `./` | `README.md`, `COMMIT_MESSAGES.md` | Docs |

**Total: ~20 arquivos modificados**