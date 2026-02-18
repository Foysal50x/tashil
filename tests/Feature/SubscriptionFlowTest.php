<?php

use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Events\SubscriptionCreated;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $this->feature = Feature::create([
        'name' => 'API Requests',
        'slug' => 'api-requests',
        'type' => 'limit',
    ]);

    $this->toggle = Feature::create([
        'name' => 'Email Support',
        'slug' => 'email-support',
        'type' => 'boolean',
    ]);

    $this->package = Package::create([
        'name'             => 'Pro Plan',
        'slug'             => 'pro-plan',
        'price'            => 29.99,
        'billing_period'   => 'month',
        'billing_interval' => 1,
        'trial_days'       => 14,
    ]);

    $this->package->features()->attach($this->feature, ['value' => '10000']);
    $this->package->features()->attach($this->toggle, ['value' => 'true']);

    $this->user = createUser();
});

it('subscribes a user to a package', function () {
    Event::fake();

    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);

    expect($subscription)->toBeInstanceOf(Subscription::class);
    expect($subscription->status)->toBe(SubscriptionStatus::Active);
    expect($subscription->package_id)->toBe($this->package->id);
    expect($subscription->subscriber_type)->toBe(get_class($this->user));
    expect($subscription->subscriber_id)->toBe($this->user->id);
    expect($subscription->auto_renew)->toBeTrue();
    expect($subscription->starts_at)->not->toBeNull();
    expect($subscription->ends_at)->not->toBeNull();

    Event::assertDispatched(SubscriptionCreated::class);
});

it('subscribes with trial', function () {
    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package, withTrial: true);

    expect($subscription->status)->toBe(SubscriptionStatus::OnTrial);
    expect($subscription->trial_ends_at)->not->toBeNull();
    expect((int) abs(round($subscription->trial_ends_at->diffInDays(now()))))->toBe(14);
});

it('syncs features as subscription items on subscribe', function () {
    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);

    expect($subscription->items)->toHaveCount(2);

    $apiItem = $subscription->items->first(fn ($item) => $item->feature_id === $this->feature->id);
    expect($apiItem->value)->toBe('10000');
    expect($apiItem->usage)->toBe(0);

    $emailItem = $subscription->items->first(fn ($item) => $item->feature_id === $this->toggle->id);
    expect($emailItem->value)->toBe('true');
});

it('cancels a subscription immediately', function () {
    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);

    $cancelled = app('tashil')->subscription()->cancel($subscription, immediate: true, reason: 'Too expensive');

    expect($cancelled->status)->toBe(SubscriptionStatus::Cancelled);
    expect($cancelled->cancelled_at)->not->toBeNull();
    expect($cancelled->cancellation_reason)->toBe('Too expensive');
    expect($cancelled->ends_at->isPast() || $cancelled->ends_at->isToday())->toBeTrue();
});

it('cancels a subscription at end of period', function () {
    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);
    $originalEndsAt = $subscription->ends_at->copy();

    $cancelled = app('tashil')->subscription()->cancel($subscription);

    expect($cancelled->status)->toBe(SubscriptionStatus::Cancelled);
    expect($cancelled->auto_renew)->toBeFalse();
    // ends_at should remain unchanged — not truncated
    expect($cancelled->ends_at->format('Y-m-d'))->toBe($originalEndsAt->format('Y-m-d'));
});

it('resumes a cancelled subscription', function () {
    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);
    app('tashil')->subscription()->cancel($subscription);

    $resumed = app('tashil')->subscription()->resume($subscription);

    expect($resumed->status)->toBe(SubscriptionStatus::Active);
    expect($resumed->cancelled_at)->toBeNull();
    expect($resumed->cancellation_reason)->toBeNull();
    expect($resumed->auto_renew)->toBeTrue();
});

it('does not resume an expired cancelled subscription', function () {
    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);
    $subscription->update([
        'status'       => 'cancelled',
        'cancelled_at' => now(),
        'ends_at'      => now()->subDay(),
    ]);

    $result = app('tashil')->subscription()->resume($subscription->refresh());

    expect($result->status)->toBe(SubscriptionStatus::Cancelled);
});

it('switches a plan', function () {
    $newPackage = Package::create([
        'name'             => 'Enterprise',
        'slug'             => 'enterprise',
        'price'            => 99.99,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);
    expect($subscription->package_id)->toBe($this->package->id);

    $newSub = app('tashil')->subscription()->switchPlan($subscription, $newPackage);

    expect($newSub->package_id)->toBe($newPackage->id);
    expect($newSub->status)->toBe(SubscriptionStatus::Active);

    // Old subscription should be cancelled
    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Cancelled);
    expect($subscription->cancellation_reason)->toBe('Plan switch');
});

it('handles lifetime subscription (no expiry)', function () {
    $lifetimePackage = Package::create([
        'name'             => 'Lifetime',
        'slug'             => 'lifetime',
        'price'            => 999.99,
        'billing_period'   => 'lifetime',
        'billing_interval' => 1,
    ]);

    $subscription = app('tashil')->subscription()->subscribe($this->user, $lifetimePackage);

    expect($subscription->ends_at)->toBeNull();
    expect($subscription->isActive())->toBeTrue();
});
