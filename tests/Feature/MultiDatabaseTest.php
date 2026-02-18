<?php

use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-database test.
 *
 * These tests verify that the package works correctly across
 * SQLite, MySQL, and PostgreSQL. They test basic CRUD and
 * relationship operations on each database driver.
 *
 * By default, tests run on SQLite. MySQL/PostgreSQL tests
 * require Docker containers to be running:
 *
 *   docker compose up -d
 *
 * Then run with:
 *   DB_CONNECTION=mysql vendor/bin/pest tests/Feature/MultiDatabaseTest.php
 *   DB_CONNECTION=pgsql vendor/bin/pest tests/Feature/MultiDatabaseTest.php
 */

it('creates and retrieves packages', function () {
    $package = Package::create([
        'name'             => 'Test Plan',
        'slug'             => 'test-plan',
        'price'            => 19.99,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    expect($package->exists)->toBeTrue();
    expect(Package::where('slug', 'test-plan')->first())->not->toBeNull();
});

it('creates features with pivot relationships', function () {
    $package = Package::create([
        'name' => 'Pro',
        'slug' => 'pro-test',
        'price' => 29.99,
    ]);

    $feature = Feature::create([
        'name' => 'API Calls',
        'slug' => 'api-calls',
        'type' => 'limit',
    ]);

    $package->features()->attach($feature, ['value' => '5000']);

    $loaded = Package::with('features')->find($package->id);
    expect($loaded->features)->toHaveCount(1);
    expect($loaded->features->first()->pivot->value)->toBe('5000');
});

it('creates subscriptions with morphs', function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $package = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro-db',
        'price'            => 29.99,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    $user = createUser();
    $subscription = app('tashil')->subscription()->subscribe($user, $package);

    expect($subscription->exists)->toBeTrue();
    expect($subscription->subscriber_type)->toBe(get_class($user));
    expect($subscription->subscriber_id)->toBe($user->id);

    // Verify morphTo loads correctly
    $loaded = Subscription::with('subscriber')->find($subscription->id);
    expect($loaded->subscriber)->not->toBeNull();
    expect($loaded->subscriber->id)->toBe($user->id);
});

it('handles soft deletes correctly', function () {
    $package = Package::create([
        'name' => 'Soft Delete Test',
        'slug' => 'soft-delete-test',
        'price' => 9.99,
    ]);

    $package->delete();

    expect(Package::where('slug', 'soft-delete-test')->count())->toBe(0);
    expect(Package::withTrashed()->where('slug', 'soft-delete-test')->count())->toBe(1);

    $package->restore();
    expect(Package::where('slug', 'soft-delete-test')->count())->toBe(1);
});

it('enforces unique constraints', function () {
    Package::create(['name' => 'Unique', 'slug' => 'unique-slug', 'price' => 9.99]);

    expect(fn () => Package::create(['name' => 'Duplicate', 'slug' => 'unique-slug', 'price' => 19.99]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('queries with scopes across drivers', function () {
    Package::create(['name' => 'Active', 'slug' => 'active', 'is_active' => true, 'is_featured' => true, 'sort_order' => 2]);
    Package::create(['name' => 'Inactive', 'slug' => 'inactive', 'is_active' => false, 'is_featured' => false, 'sort_order' => 1]);

    expect(Package::active()->count())->toBe(1);
    expect(Package::featured()->count())->toBe(1);

    $ordered = Package::ordered()->pluck('slug')->toArray();
    expect($ordered)->toBe(['inactive', 'active']);
});
