<?php

namespace Foysal50x\Tashil\Tests;

use Foysal50x\Tashil\Providers\TashilServiceProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Unguard models during tests for easier factory creation
        Model::unguard();
    }

    /**
     * Get package providers.
     */
    protected function getPackageProviders($app): array
    {
        return [
            TashilServiceProvider::class,
        ];
    }

    /**
     * Define environment setup.
     */
    protected function defineEnvironment($app): void
    {
        // Default: SQLite in-memory
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        // MySQL connection (available when Docker is running)
        $app['config']->set('database.connections.mysql', [
            'driver'   => 'mysql',
            'host'     => env('MYSQL_HOST', '127.0.0.1'),
            'port'     => env('MYSQL_PORT', '13306'),
            'database' => env('MYSQL_DATABASE', 'tashil_test'),
            'username' => env('MYSQL_USERNAME', 'root'),
            'password' => env('MYSQL_PASSWORD', 'tashil'),
            'charset'  => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'   => '',
        ]);

        // PostgreSQL connection (available when Docker is running)
        $app['config']->set('database.connections.pgsql', [
            'driver'   => 'pgsql',
            'host'     => env('PGSQL_HOST', '127.0.0.1'),
            'port'     => env('PGSQL_PORT', '15432'),
            'database' => env('PGSQL_DATABASE', 'tashil_test'),
            'username' => env('PGSQL_USERNAME', 'tashil'),
            'password' => env('PGSQL_PASSWORD', 'tashil'),
            'charset'  => 'utf8',
            'prefix'   => '',
            'schema'   => 'public',
        ]);

        // Tashil uses the testing connection by default
        $app['config']->set('tashil.database.connection', null);

        // Disable Redis in tests by default (use array cache)
        $app['config']->set('cache.stores.tashil', [
            'driver' => 'array',
        ]);
    }

    /**
     * Define database migrations.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/Fixtures');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }
}
