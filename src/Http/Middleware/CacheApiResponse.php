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
    public function handle(Request $request, Closure $next, ?string $ttl = null, ?string $entityTag = null): Response
    {
        if (!$request->isMethod('get')) {
            return $next($request);
        }

        $cacheTtlInSeconds = (int) $ttl;
        if ($cacheTtlInSeconds <= 0) {
            $entityTag = $ttl;
            $ttl = 3600;
        }
        $cacheKey = 'api_response:' . md5($request->fullUrl());

        $supportsTags = $this->supportsTags();
        $tags = ['api_response'];
        $repositoryTags = \RiseTechApps\Repository\Repository::getTagsCache();

        if ($entityTag) {
            $tags[] = $entityTag;
        }

        if (!empty($repositoryTags)) {
            $tags = array_merge($tags, $repositoryTags);
        }

        // Cache com tags se suportado, senão cache simples.
        $store = $supportsTags ? Cache::tags($tags) : Cache::store();

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

    /**
     * Filtra os cabeçalhos que podem ser servidos do cache.
     *
     * set-cookie carrega a sessão/CSRF do usuário que gerou a resposta —
     * servi-lo cacheado a outro usuário vazaria a sessão. date/x-request-id
     * são voláteis e não devem ser congelados no cache.
     */
    private function cacheableHeaders(Response $response): array
    {
        $headers = $response->headers->all();

        unset(
            $headers['set-cookie'],
            $headers['date'],
            $headers['x-request-id'],
        );

        return $headers;
    }
}
