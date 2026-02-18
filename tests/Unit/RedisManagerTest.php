<?php

use Foysal50x\Tashil\Managers\RedisManager;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    $this->connection = Mockery::mock(Connection::class);
    Redis::shouldReceive('connection')
        ->with('tashil')
        ->andReturn($this->connection);

    $this->manager = new RedisManager('tashil');
});

it('returns a redis connection', function () {
    $connection = $this->manager->connection();

    expect($connection)->toBe($this->connection);
});

it('gets a value by key', function () {
    $this->connection->shouldReceive('get')
        ->with('cache:key')
        ->once()
        ->andReturn('cached-value');

    expect($this->manager->get('cache:key'))->toBe('cached-value');
});

it('sets a value without TTL', function () {
    $this->connection->shouldReceive('set')
        ->with('cache:key', 'value')
        ->once();

    $this->manager->set('cache:key', 'value');
});

it('sets a value with TTL using setex', function () {
    $this->connection->shouldReceive('setex')
        ->with('cache:key', 3600, 'value')
        ->once();

    $this->manager->set('cache:key', 'value', 3600);
});

it('deletes keys', function () {
    $this->connection->shouldReceive('del')
        ->with('key1', 'key2')
        ->once()
        ->andReturn(2);

    expect($this->manager->del('key1', 'key2'))->toBe(2);
});

it('executes arbitrary redis commands', function () {
    $this->connection->shouldReceive('command')
        ->with('ping', [])
        ->once()
        ->andReturn('PONG');

    expect($this->manager->command('ping'))->toBe('PONG');
});

it('uses default connection name', function () {
    Redis::shouldReceive('connection')
        ->with('tashil')
        ->andReturn($this->connection);

    $manager = new RedisManager();
    expect($manager->connection())->toBe($this->connection);
});
