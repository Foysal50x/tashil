<?php

use Foysal50x\Tashil\Contracts\FeatureRepositoryInterface;
use Foysal50x\Tashil\Contracts\PackageRepositoryInterface;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Enums\ResetPeriod;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Managers\CacheManager;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\FeatureUsage;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionEvent;
use Foysal50x\Tashil\Models\UsageLog;
use Foysal50x\Tashil\Repositories\Cache\CacheFeatureRepository;
use Foysal50x\Tashil\Repositories\Cache\CachePackageRepository;
use Foysal50x\Tashil\Repositories\Cache\CacheSubscriptionRepository;
use Foysal50x\Tashil\Repositories\EloquentFeatureUsageRepository;
use Foysal50x\Tashil\Services\EventStore;
use Foysal50x\Tashil\Services\Resetter;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');
});

// ─────────────────────────────────────────────────────────────────────
// #11 Boolean/Enum guard in UsageService.increment
// ─────────────────────────────────────────────────────────────────────

it('rejects increment on a Boolean feature even though limit_value is NULL', function () {
    $feature = Feature::create(['name' => 'Dark Mode', 'slug' => 'dark-mode', 'type' => 'boolean']);
    $package = Package::create([
        'name'           => 'Pro', 'slug' => 'pro', 'price' => 0,
        'billing_period' => 'month', 'billing_interval' => 1,
    ]);
    $package->features()->attach($feature, ['value' => '1']);

    $user = createUser();
    $subscription = app('tashil')->subscription()->subscribe($user, $package);

    $ok = app('tashil')->usage()->increment($subscription, 'dark-mode', 1);

    expect($ok)->toBeFalse();
    expect((float) FeatureUsage::first()->usage)->toBe(0.0);
    expect(UsageLog::count())->toBe(0);
});

it('rejects increment on an Enum feature', function () {
    $feature = Feature::create(['name' => 'Theme', 'slug' => 'theme', 'type' => 'enum']);
    $package = Package::create([
        'name'           => 'Pro', 'slug' => 'pro', 'price' => 0,
        'billing_period' => 'month', 'billing_interval' => 1,
    ]);
    $package->features()->attach($feature, ['value' => 'dark']);

    $user = createUser();
    $subscription = app('tashil')->subscription()->subscribe($user, $package);

    expect(app('tashil')->usage()->increment($subscription, 'theme', 1))->toBeFalse();
    expect(UsageLog::count())->toBe(0);
});

it('rejects reportStorage on Boolean and Enum features', function () {
    $bool = Feature::create(['name' => 'Bool', 'slug' => 'bool', 'type' => 'boolean']);
    $enum = Feature::create(['name' => 'Enum', 'slug' => 'enum', 'type' => 'enum']);
    $package = Package::create([
        'name'           => 'Pro', 'slug' => 'pro', 'price' => 0,
        'billing_period' => 'month', 'billing_interval' => 1,
    ]);
    $package->features()->attach($bool, ['value' => '1']);
    $package->features()->attach($enum, ['value' => 'dark']);

    $user = createUser();
    $subscription = app('tashil')->subscription()->subscribe($user, $package);

    expect(app('tashil')->usage()->reportStorage($subscription, 'bool', 5))->toBeFalse();
    expect(app('tashil')->usage()->reportStorage($subscription, 'enum', 5))->toBeFalse();
});

// ─────────────────────────────────────────────────────────────────────
// #12 stale period_end handling in check() and increment()
// ─────────────────────────────────────────────────────────────────────

it('check() treats an expired period as if the counter were reset', function () {
    $feature = Feature::create([
        'name' => 'API', 'slug' => 'api', 'type' => 'limit', 'reset_period' => 'monthly',
    ]);
    $package = Package::create([
        'name'           => 'Pro', 'slug' => 'pro', 'price' => 0,
        'billing_period' => 'month', 'billing_interval' => 1,
    ]);
    $package->features()->attach($feature, ['value' => '100']);

    $user = createUser();
    $subscription = app('tashil')->subscription()->subscribe($user, $package);

    $usage = FeatureUsage::where('subscription_id', $subscription->id)->first();
    $usage->update([
        'usage'      => 100,
        'period_end' => now()->subDay(),
    ]);

    // Without the expired-period guard this would be false (100 + 1 > 100).
    expect(app('tashil')->usage()->check($subscription, 'api', 1))->toBeTrue();
});

it('increment() inline-resets the counter when the period has elapsed', function () {
    $feature = Feature::create([
        'name' => 'API', 'slug' => 'api', 'type' => 'limit', 'reset_period' => 'monthly',
    ]);
    $package = Package::create([
        'name'           => 'Pro', 'slug' => 'pro', 'price' => 0,
        'billing_period' => 'month', 'billing_interval' => 1,
    ]);
    $package->features()->attach($feature, ['value' => '100']);

    $user = createUser();
    $subscription = app('tashil')->subscription()->subscribe($user, $package);

    $usage = FeatureUsage::where('subscription_id', $subscription->id)->first();
    $usage->update([
        'usage'      => 100,
        'period_end' => now()->subDay(),
    ]);

    // 100 + 1 against the stale counter would fail; the inline reset
    // zeroes it first so the increment succeeds and lands at 1.
    expect(app('tashil')->usage()->increment($subscription, 'api', 1))->toBeTrue();
    expect((float) FeatureUsage::first()->usage)->toBe(1.0);

    // And the period_end advanced — we're no longer in the expired window.
    expect(FeatureUsage::first()->period_end->isFuture())->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────
// #16 Lifetime subscription auto_renew is false
// ─────────────────────────────────────────────────────────────────────

it('lifetime subscriptions get auto_renew=false', function () {
    $package = Package::create([
        'name'           => 'Lifetime', 'slug' => 'lifetime', 'price' => 100,
        'billing_period' => Period::Lifetime->value, 'billing_interval' => 1,
    ]);

    $user = createUser();
    $subscription = app('tashil')->subscription()->subscribe($user, $package);

    expect($subscription->auto_renew)->toBeFalse();
    expect($subscription->current_period_end)->toBeNull();
});

it('non-lifetime subscriptions keep auto_renew=true', function () {
    $package = Package::create([
        'name'           => 'Pro', 'slug' => 'pro', 'price' => 10,
        'billing_period' => 'month', 'billing_interval' => 1,
    ]);

    $user = createUser();
    $subscription = app('tashil')->subscription()->subscribe($user, $package);

    expect($subscription->auto_renew)->toBeTrue();
});

// ─────────────────────────────────────────────────────────────────────
// #22 nextPeriodEnd safety cap
// ─────────────────────────────────────────────────────────────────────

it('nextPeriodEnd advances normally for typical inputs', function () {
    $anchor = now()->subMonth();
    $next = EloquentFeatureUsageRepository::nextPeriodEnd(ResetPeriod::Monthly, $anchor, now());

    expect($next)->not->toBeNull();
    expect($next->isFuture())->toBeTrue();
});

it('nextPeriodEnd throws when advancement exceeds the safety cap', function () {
    // Daily advance from a Carbon decades behind a far-future $now
    // would loop tens of thousands of times. Cap fires first.
    $anchor = now()->subYears(50);
    $now = now()->addYears(50);

    expect(fn () => EloquentFeatureUsageRepository::nextPeriodEnd(ResetPeriod::Daily, $anchor, $now))
        ->toThrow(RuntimeException::class, 'nextPeriodEnd exceeded');
});

// ─────────────────────────────────────────────────────────────────────
// #24 FeatureBuilder.resetPeriod
// ─────────────────────────────────────────────────────────────────────

it('FeatureBuilder.resetPeriod persists the reset cadence', function () {
    $feature = app('tashil')->feature('api-calls')
        ->limit()
        ->resetPeriod(ResetPeriod::Monthly)
        ->create();

    expect($feature->reset_period)->toBe(ResetPeriod::Monthly);
});

it('FeatureBuilder reset shorthand methods set the right enum', function () {
    $daily = app('tashil')->feature('daily-feature')->limit()->resetDaily()->create();
    $weekly = app('tashil')->feature('weekly-feature')->limit()->resetWeekly()->create();
    $monthly = app('tashil')->feature('monthly-feature')->limit()->resetMonthly()->create();
    $yearly = app('tashil')->feature('yearly-feature')->limit()->resetYearly()->create();

    expect($daily->reset_period)->toBe(ResetPeriod::Daily);
    expect($weekly->reset_period)->toBe(ResetPeriod::Weekly);
    expect($monthly->reset_period)->toBe(ResetPeriod::Monthly);
    expect($yearly->reset_period)->toBe(ResetPeriod::Yearly);
});

it('FeatureBuilder defaults reset_period to Never', function () {
    $feature = app('tashil')->feature('no-reset')->limit()->create();
    expect($feature->reset_period)->toBe(ResetPeriod::Never);
});

// ─────────────────────────────────────────────────────────────────────
// #14 Renewal command re-verifies status before issuing invoice
// ─────────────────────────────────────────────────────────────────────

it('renewal command skips subscriptions cancelled between query and lock', function () {
    $package = Package::create([
        'name'           => 'Pro', 'slug' => 'pro', 'price' => 10,
        'billing_period' => 'month', 'billing_interval' => 1,
    ]);

    $user = createUser();
    $subscription = app('tashil')->subscription()->subscribe($user, $package);

    // Backdate period so it qualifies for renewal.
    $subscription->update(['current_period_end' => now()->subHour()]);

    // Simulate the race: another process cancels after dueForRenewal()
    // returns the subscription but before the renewal transaction runs.
    // We trigger it inline by cancelling immediately and then invoking
    // the command — the re-verify guard should skip without billing.
    app('tashil')->subscription()->cancel($subscription, immediate: true);

    $invoiceCountBefore = $subscription->invoices()->count();

    $exit = Artisan::call('tashil:renew-subscriptions');

    expect($exit)->toBe(0);
    expect($subscription->invoices()->count())->toBe($invoiceCountBefore);
});

// ─────────────────────────────────────────────────────────────────────
// #13 Resetter batches transactions
// ─────────────────────────────────────────────────────────────────────

it('Resetter processes due rows from multiple chunks', function () {
    $feature = Feature::create([
        'name' => 'API', 'slug' => 'api', 'type' => 'limit', 'reset_period' => 'monthly',
    ]);
    $package = Package::create([
        'name'           => 'Pro', 'slug' => 'pro', 'price' => 0,
        'billing_period' => 'month', 'billing_interval' => 1,
    ]);
    $package->features()->attach($feature, ['value' => '100']);

    // Create three subscriptions; backdate each usage row so all qualify.
    $subscriptions = collect(range(1, 3))->map(function () use ($package) {
        $user = createUser();

        return app('tashil')->subscription()->subscribe($user, $package);
    });

    foreach ($subscriptions as $sub) {
        FeatureUsage::where('subscription_id', $sub->id)->update([
            'usage'      => 50,
            'period_end' => now()->subDay(),
        ]);
    }

    /** @var Resetter $resetter */
    $resetter = app(Resetter::class);

    // Force chunks of 2 so we exercise multi-chunk iteration with 3 rows.
    $reset = $resetter->resetDueQuotas(now(), batchSize: 2);

    expect($reset)->toBe(3);
    expect(FeatureUsage::query()->where('usage', '>', 0)->count())->toBe(0);
});

// ─────────────────────────────────────────────────────────────────────
// #6/#8/#9 Cache invalidation surfaces (covered indirectly via behavior)
// ─────────────────────────────────────────────────────────────────────

it('CacheFeatureRepository.updateOrCreate forgets cached listing keys', function () {
    $cacheManager = Mockery::mock(CacheManager::class)->makePartial();
    $store = Mockery::mock(Repository::class);
    $cacheManager->shouldReceive('store')->andReturn($store);
    $store->shouldReceive('forget')->with('features:all')->once()->andReturn(true);
    $store->shouldReceive('forget')->with(Mockery::pattern('/feature:.*/'))->twice()->andReturn(true);

    $repository = Mockery::mock(FeatureRepositoryInterface::class);
    $feature = new Feature(['slug' => 'api', 'name' => 'API', 'type' => 'limit']);
    $feature->id = 42;
    $repository->shouldReceive('updateOrCreate')->andReturn($feature);

    $repo = new CacheFeatureRepository(
        $repository,
        $cacheManager,
        cacheTtl: 3600,
        cachePrefix: 'features',
    );

    $repo->updateOrCreate(['slug' => 'api'], ['slug' => 'api', 'name' => 'API v2']);
});

it('CachePackageRepository.create invalidates the packages listing keys', function () {
    $cacheManager = Mockery::mock(CacheManager::class)->makePartial();
    $store = Mockery::mock(Repository::class);
    $cacheManager->shouldReceive('store')->andReturn($store);
    $store->shouldReceive('forget')->with('packages:all')->once()->andReturn(true);
    $store->shouldReceive('forget')->with('packages:active')->once()->andReturn(true);
    $store->shouldReceive('forget')->with(Mockery::pattern('/package:.*/'))->atLeast()->once()->andReturn(true);

    $repository = Mockery::mock(PackageRepositoryInterface::class);
    $package = new Package(['slug' => 'x', 'name' => 'X']);
    $package->id = 42;
    $repository->shouldReceive('create')->andReturn($package);

    $repo = new CachePackageRepository(
        $repository,
        $cacheManager,
        cacheTtl: 3600,
        cachePrefix: 'packages',
    );

    $repo->create(['slug' => 'x']);
});

it('CacheSubscriptionRepository.update invalidates the per-package has key on plan switch', function () {
    $packageA = Package::create([
        'name'           => 'A', 'slug' => 'pkg-a', 'price' => 0,
        'billing_period' => 'month', 'billing_interval' => 1,
    ]);
    $packageB = Package::create([
        'name'           => 'B', 'slug' => 'pkg-b', 'price' => 0,
        'billing_period' => 'month', 'billing_interval' => 1,
    ]);

    $user = createUser();
    $subscription = Subscription::create([
        'subscriber_type'      => $user->getSubscriberType(),
        'subscriber_id'        => $user->getSubscriberKey(),
        'package_id'           => $packageA->id,
        'status'               => SubscriptionStatus::Active,
        'starts_at'            => now(),
        'current_period_start' => now(),
        'current_period_end'   => now()->addMonth(),
        'auto_renew'           => true,
    ]);

    $base = class_basename($user->getSubscriberType());
    $sid = $user->getSubscriberKey();

    $cacheManager = Mockery::mock(CacheManager::class)->makePartial();
    $store = Mockery::mock(Repository::class);
    $cacheManager->shouldReceive('store')->andReturn($store);

    $expectedKeys = [
        "subscription:{$base}:{$sid}:valid",
        "subscription:{$base}:{$sid}:has:any",
        "subscription:{$base}:{$sid}:has:{$packageA->id}",
        "subscription:{$base}:{$sid}:has:{$packageB->id}",
        "subscription:{$base}:{$sid}:has:pkg-b",
    ];
    foreach ($expectedKeys as $key) {
        $store->shouldReceive('forget')->with($key)->once()->andReturn(true);
    }
    // Aggregate keys plus other tolerated forgets — soak the rest up.
    $store->shouldReceive('forget')->withAnyArgs()->andReturn(true);

    $innerRepo = Mockery::mock(SubscriptionRepositoryInterface::class);
    $subscription->setRelation('package', $packageB);
    $subscription->package_id = $packageB->id;
    $innerRepo->shouldReceive('update')->andReturn($subscription);

    $repo = new CacheSubscriptionRepository(
        $innerRepo,
        $cacheManager,
        cacheTtl: 3600,
        cachePrefix: 'subscriptions',
    );

    // Simulate the original package_id being A so getOriginal() returns A.
    $subscription->syncOriginal();
    $subscription->setRawAttributes(array_merge($subscription->getAttributes(), ['package_id' => $packageA->id]), true);
    $subscription->package_id = $packageB->id;

    $repo->update($subscription, ['package_id' => $packageB->id]);
});

// ─────────────────────────────────────────────────────────────────────
// #15 atomicIncrement parameter binding cleanup — round-trips a value
// ─────────────────────────────────────────────────────────────────────

it('atomicIncrement still increments via parameterized UPDATE', function () {
    $feature = Feature::create([
        'name' => 'API', 'slug' => 'api', 'type' => 'limit', 'reset_period' => 'never',
    ]);
    $package = Package::create([
        'name'           => 'Pro', 'slug' => 'pro', 'price' => 0,
        'billing_period' => 'month', 'billing_interval' => 1,
    ]);
    $package->features()->attach($feature, ['value' => '100']);

    $user = createUser();
    $subscription = app('tashil')->subscription()->subscribe($user, $package);

    expect(app('tashil')->usage()->increment($subscription, 'api', 7.5))->toBeTrue();
    expect((float) FeatureUsage::first()->usage)->toBe(7.5);
});

// ─────────────────────────────────────────────────────────────────────
// #23 EventStore retry recovers from a desynced last_event_seq
// ─────────────────────────────────────────────────────────────────────

it('EventStore recovers when an out-of-band insert collides with the next sequence', function () {
    $package = Package::create([
        'name'           => 'Pro', 'slug' => 'pro', 'price' => 0,
        'billing_period' => 'month', 'billing_interval' => 1,
    ]);

    $user = createUser();
    $subscription = app('tashil')->subscription()->subscribe($user, $package);

    // subscription.created event already at seq=1, last_event_seq=1.
    // An out-of-band raw insert places another event at seq=2 without
    // updating last_event_seq. The next append computes nextSeq=2, hits
    // the UNIQUE(subscription_id, sequence_num) constraint, and the retry
    // recomputes from max(sequence_num)+1.
    SubscriptionEvent::create([
        'event_id'        => (string) Str::uuid(),
        'subscription_id' => $subscription->id,
        'event_type'      => 'manual.injection',
        'sequence_num'    => 2,
        'payload'         => [],
        'metadata'        => [],
        'occurred_at'     => now(),
        'recorded_at'     => now(),
    ]);

    /** @var EventStore $store */
    $store = app(EventStore::class);
    $event = $store->append($subscription, 'recovery.test');

    expect($event->sequence_num)->toBe(3);
});
