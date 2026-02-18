<?php

use Foysal50x\Tashil\Managers\DatabaseManager;
use Illuminate\Database\Connection;

it('returns a database connection', function () {
    $manager = new DatabaseManager();

    expect($manager->connection())->toBeInstanceOf(Connection::class);
});

it('builds table name with prefix', function () {
    $manager = new DatabaseManager();

    $table = $manager->getTable('subscriptions');

    expect($table)->toBe('tashil_subscriptions');
});

it('uses configured prefix', function () {
    config(['tashil.database.prefix' => 'custom_']);

    $manager = new DatabaseManager();

    expect($manager->getTable('invoices'))->toBe('custom_invoices');
});

it('uses configured table name override', function () {
    config(['tashil.database.tables.subscriptions' => 'my_subs']);

    $manager = new DatabaseManager();

    expect($manager->getTable('subscriptions'))->toBe('tashil_my_subs');
});

it('returns a query builder for a table', function () {
    $manager = new DatabaseManager();

    $query = $manager->query('packages');

    expect($query)->toBeInstanceOf(\Illuminate\Database\Query\Builder::class);
});
