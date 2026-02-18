<?php

namespace Foysal50x\Tashil\Managers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Contracts\Cache\Repository;

class CacheManager
{
    protected string $storeName;

    public function __construct(string $storeName = 'tashil')
    {
        $this->storeName = $storeName;
    }

    public function store(): Repository
    {
        return Cache::store($this->storeName);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store()->get($key, $default);
    }

    public function put(string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null): bool
    {
        return $this->store()->put($key, $value, $ttl);
    }

    public function forget(string $key): bool
    {
        return $this->store()->forget($key);
    }

    public function has(string $key): bool
    {
        return $this->store()->has($key);
    }

    public function increment(string $key, int $value = 1): int|bool
    {
        return $this->store()->increment($key, $value);
    }

    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->store()->decrement($key, $value);
    }

    public function add(string $key, mixed $value, \DateTimeInterface|\DateInterval|int|null $ttl = null): bool
    {
        return $this->store()->add($key, $value, $ttl);
    }

    public function remember(string $key, \DateTimeInterface|\DateInterval|int|null $ttl = null, callable $callback): mixed
    {
        return $this->store()->remember($key, $ttl, $callback);
    }

    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->store()->rememberForever($key, $callback);
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        return $this->store()->pull($key, $default);
    }

    /**
     * Begin executing a new tags operation.
     *
     * Note: This method is only supported by cache drivers that support tagging
     * (e.g., redis, memcached). It will throw an exception if used with
     * drivers that do not support tags (e.g., file, database).
     *
     * @param  array  $names
     * @return mixed
     */
    public function tags(array $names): mixed
    {
        return $this->store()->tags($names);
    }

    public function flush(): bool
    {
        return $this->store()->flush();
    }
}
