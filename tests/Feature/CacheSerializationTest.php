<?php

declare(strict_types=1);

use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Managers\CacheManager;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Repositories\Cache\CacheSubscriptionRepository;
use Foysal50x\Tashil\Repositories\EloquentSubscriptionRepository;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $this->package = Package::create([
        'name'             => 'Pro Monthly',
        'slug'             => 'pro-monthly',
        'price'            => 29.99,
        'currency'         => 'USD',
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    $this->cacheManager = new CacheManager('tashil');
    $this->cacheManager->flush();

    $this->repository = new CacheSubscriptionRepository(
        new EloquentSubscriptionRepository,
        $this->cacheManager,
        3600,
        'tashil',
    );
});

/**
 * Recursively assert a value survives unserialize(..., ['allowed_classes' => false]),
 * i.e. what a host with cache.serializable_classes = false does on every cache hit.
 */
function assertSerializerSafe(mixed $value): void
{
    $roundTripped = unserialize(serialize($value), ['allowed_classes' => false]);

    $containsIncompleteClass = function (mixed $v) use (&$containsIncompleteClass): bool {
        if ($v instanceof __PHP_Incomplete_Class) {
            return true;
        }
        if (is_object($v)) {
            return true; // any object at all would have been stripped
        }
        if (is_array($v)) {
            foreach ($v as $item) {
                if ($containsIncompleteClass($item)) {
                    return true;
                }
            }
        }

        return false;
    };

    expect($containsIncompleteClass($roundTripped))->toBeFalse();
}

function rawCachedValue(CacheManager $cacheManager, string $key): mixed
{
    $store = $cacheManager->store();

    if (method_exists($store, 'tags')) {
        try {
            return $store->tags(['tashil'])->get($key);
        } catch (BadMethodCallException) {
            // store does not support tagging; fall through
        }
    }

    return $cacheManager->get($key);
}

it('caches models as serializer-safe payloads and rehydrates them on hit', function () {
    $user = createUser();
    $subscription = Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    // Warm the cache (miss) — must still return a real model.
    $warm = $this->repository->findById($subscription->id);
    expect($warm)->toBeInstanceOf(Subscription::class)
        ->and($warm->id)->toBe($subscription->id);

    // The raw cached payload must survive a strict unserialize with
    // allowed_classes=false — no objects allowed in the cache.
    $raw = rawCachedValue($this->cacheManager, "subscription:{$subscription->id}");
    expect($raw)->not->toBeNull();
    assertSerializerSafe($raw);

    // Cache hit — must rehydrate into a working model.
    $hit = $this->repository->findById($subscription->id);
    expect($hit)->toBeInstanceOf(Subscription::class)
        ->and($hit->id)->toBe($subscription->id)
        ->and($hit->exists)->toBeTrue()
        ->and($hit->status)->toBe(SubscriptionStatus::Active)
        ->and($hit->starts_at)->toBeInstanceOf(Carbon::class);
});

it('caches null results and plain values untouched', function () {
    expect($this->repository->findById(999))->toBeNull()
        ->and($this->repository->findById(999))->toBeNull();

    $stats = $this->repository->analyticsByPackage();
    expect($stats)->toBeArray();
    assertSerializerSafe(rawCachedValue($this->cacheManager, 'subscription:aggregate:analytics_by_package'));
});

it('rehydrates cached models with loaded relations', function () {
    $user = createUser();
    $subscription = Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    $valid = $this->repository->findValidForSubscriber($user);
    expect($valid)->toBeInstanceOf(Subscription::class);

    // Hit path again — enum + datetime casts must still work after rehydration.
    $hit = $this->repository->findValidForSubscriber($user);
    expect($hit)->toBeInstanceOf(Subscription::class)
        ->and($hit->id)->toBe($subscription->id)
        ->and($hit->package->id)->toBe($this->package->id);
});
