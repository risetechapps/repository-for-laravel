<?php

namespace RiseTechApps\Repository\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use RiseTechApps\Repository\Repository;
use Symfony\Component\HttpFoundation\Response;

class CacheApiResponse
{
    /**
     * Verifica se o store do repositório suporta tags.
     */
    private function supportsTags(): bool
    {
        return Repository::storeSupportsTags();
    }

    /**
     * Handle an incoming request.
     *
     * @param \Closure(Request): (Response) $next
     * @param int|null $ttl
     * @return Response
     */
    public function handle(Request $request, Closure $next, ?string $ttl = null, ?string $entityTag = null, string $scope = 'auth'): Response
    {
        if (!$request->isMethod('get') || !Repository::cacheEnabled()) {
            return $next($request);
        }

        $cacheTtlInSeconds = (int) $ttl;
        if ($cacheTtlInSeconds <= 0) {
            $entityTag = $ttl;
            $ttl = 3600;
        }

        // `cacheResponse:600,,public` — tag vazia = sem tag.
        if ($entityTag === '') {
            $entityTag = null;
        }

        $cacheKey = 'api_response:' . md5($request->fullUrl());

        // Por padrão a resposta de um usuário logado é cacheada só para ele: a
        // mesma URL pode devolver dados diferentes por usuário (permissões,
        // filiais, /me). Rotas de conteúdo idêntico para todos usam `public`.
        if ($scope !== 'public' && $request->user()) {
            $cacheKey .= ':user_' . $request->user()->getAuthIdentifier();
        }

        $supportsTags = $this->supportsTags();
        $tags = ['api_response'];
        $repositoryTags = \RiseTechApps\Repository\Repository::getTagsCache();

        // Normaliza a tag da entidade para notação com ponto — é assim que o
        // repositório a limpa (flushEntityCache: str_replace('\\','.', classe)).
        // Aceita tanto 'App\Models\Client' quanto 'App.Models.Client' na rota,
        // e ambos casam com o flush do write.
        if ($entityTag) {
            $tags[] = str_replace('\\', '.', $entityTag);
        }

        if (!empty($repositoryTags)) {
            $tags = array_merge($tags, $repositoryTags);
        }

        // Cache com tags se suportado, senão cache simples — sempre no MESMO
        // store do repositório (Repository::store()), para que a invalidação do
        // write (flushEntityCache) atinja estas entradas mesmo quando o store
        // do repositório difere do default da app.
        $repositoryStore = Repository::store();
        $store = $supportsTags ? $repositoryStore->tags($tags) : $repositoryStore;

        // Uma única leitura do cache. O valor armazenado é sempre um array;
        // null = miss — dispensa o has()+get() de duas idas ao driver.
        $cachedResponse = $store->get($cacheKey);

        if (is_array($cachedResponse)) {
            $response = response($cachedResponse['content'], $cachedResponse['status'] ?? 200);

            if (isset($cachedResponse['headers']) && is_array($cachedResponse['headers'])) {
                foreach ($cachedResponse['headers'] as $name => $value) {
                    $response->header($name, $value);
                }
            }
            $response->header('X-Cached-By', 'cache-response-api');

            return $response;
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            $dataToCache = [
                'content' => $response->getContent(),
                'status' => $response->getStatusCode(),
                'headers' => $this->cacheableHeaders($response),
            ];

            $store->put($cacheKey, $dataToCache, $ttl);
        }

        return $response;
    }

    private array $allowedHeaders = [
        'content-type',
        'cache-control',
        'pragma',
        'expires',
        'x-ratelimit-limit',
        'x-ratelimit-remaining',
        'x-ratelimit-reset',
    ];

    /**
     * Filtra os cabeçalhos que podem ser servidos do cache.
     *
     * Apenas cabeçalhos seguros e não-voláteis são mantidos:
     * - set-cookie/date/x-request-id são removidos (vazamento de sessão
     *   ou voláteis).
     * - content-type é validado contra tipos conhecidos.
     * - Qualquer outro cabeçalho é descartado.
     */
    private function cacheableHeaders(Response $response): array
    {
        $headers = $response->headers->all();

        $safe = [];
        foreach ($this->allowedHeaders as $name) {
            if (isset($headers[$name])) {
                $safe[$name] = $headers[$name];
            }
        }

        return $safe;
    }
}
