<?php

namespace RiseTechApps\Repository\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CacheApiResponse
{
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

        $tags = ['api_response'];
        $repositoryTags = \RiseTechApps\Repository\Repository::getTagsCache();

        if ($entityTag) {
            $tags[] = $entityTag;
        }

        if (!empty($repositoryTags)) {
            $tags = array_merge($tags, $repositoryTags);
        }

        if (Cache::tags($tags)->has($cacheKey)) {
            $cachedResponse = Cache::tags($tags)->get($cacheKey);

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
                'headers' => $response->headers->all(),
            ];
            Cache::tags($tags)->put($cacheKey, $dataToCache, $ttl);
        }

        return $response;
    }
}
