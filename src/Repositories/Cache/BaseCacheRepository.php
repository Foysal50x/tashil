<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Repositories\Cache;

use Foysal50x\Tashil\Managers\CacheManager;

abstract class BaseCacheRepository
{
    protected array $cacheTags;
    
    public function __construct(
        protected readonly mixed $repository,
        protected readonly CacheManager $cacheManager,
        protected readonly int $cacheTtl,
        protected readonly string $cachePrefix,
    ) {
        $this->cacheTags = [$this->cachePrefix];
    }

    /**
     * Generate cache prefix from repository class name.
     */
    protected function generateCachePrefix(): string
    {
        $className = class_basename($this->repository);
        return strtolower(str_replace('Repository', '', $className));
    }

    /**
     * Generate cache key.
     */
    protected function getCacheKey(string $method, array $args = []): string
    {
        $key = $this->generateCachePrefix() . ':' . $method;
        
        if (!empty($args)) {
            $key .= ':' . md5(serialize($args));
        }
        
        return $key;
    }

    protected function remember(string $key, callable $callback): mixed {
        if (method_exists($this->cacheManager->store(), 'tags')) {
            return $this->cacheManager->tags($this->cacheTags)->remember($key, $this->cacheTtl, $callback);
        }

        return $this->cacheManager->remember($key, $this->cacheTtl, $callback);
    }

    protected function forget(string $key): bool
    {
        if (method_exists($this->cacheManager->store(), 'tags')) {
            return $this->cacheManager->tags($this->cacheTags)->forget($key);
        }

        return $this->cacheManager->forget($key);
    }

    /**
     * Clear all cache for this repository.
     */
    public function clearCache(): void
    {
        if (method_exists($this->cacheManager->store(), 'tags')) {
            $this->cacheManager->tags($this->cacheTags)->flush();
        } else {
            $this->cacheManager->flush();
        }
    }


}
