<?php

namespace Foysal50x\Tashil\Managers;

use Illuminate\Support\Facades\Redis;
use Illuminate\Redis\Connections\Connection;

class RedisManager
{
    protected string $connectionName;

    public function __construct(string $connectionName = 'tashil')
    {
        $this->connectionName = $connectionName;
    }

    public function connection(): Connection
    {
        return Redis::connection($this->connectionName);
    }

    public function get(string $key): mixed
    {
        return $this->connection()->get($key);
    }

    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl) {
            $this->connection()->setex($key, $ttl, $value);
        } else {
            $this->connection()->set($key, $value);
        }
    }

    public function del(string ...$keys): int
    {
        return $this->connection()->del(...$keys);
    }

    public function command(string $method, array $parameters = []): mixed
    {
        return $this->connection()->command($method, $parameters);
    }
}
