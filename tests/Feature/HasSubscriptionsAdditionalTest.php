<?php

use Foysal50x\Tashil\Enums\FeatureType;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionItem;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $this->feature = Feature::create([
        'name' => 'API Requests',
        'slug' => 'api-requests',
        'type' => FeatureType::Limit,
    ]);

    $this->toggle = Feature::create([
        'name' => 'Email Support',
        'slug' => 'email-support',
        'type' => FeatureType::Boolean,
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

// ── loadSubscription / clearSubscriptionCache ───────────────────────

it('caches subscription on loadSubscription', function () {
    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);

    // First call loads from DB
    $loaded = $this->user->loadSubscription();
    expect($loaded)->toBeInstanceOf(Subscription::class);
    expect($loaded->id)->toBe($subscription->id);

    // Second call returns same cached instance
    $cached = $this->user->loadSubscription();
    expect($cached)->toBe($loaded);
});

it('clears subscription cache', function () {
    app('tashil')->subscription()->subscribe($this->user, $this->package);

    $this->user->loadSubscription();
    $this->user->clearSubscriptionCache();

    // After clearing, the next call should re-resolve (still returns valid sub)
    $fresh = $this->user->loadSubscription();
    expect($fresh)->toBeInstanceOf(Subscription::class);
});

// ── onTrial ─────────────────────────────────────────────────────────

it('returns true for onTrial when subscribed with trial', function () {
    app('tashil')->subscription()->subscribe($this->user, $this->package, withTrial: true);

    expect($this->user->onTrial())->toBeTrue();
});

it('returns false for onTrial when subscribed without trial', function () {
    app('tashil')->subscription()->subscribe($this->user, $this->package);

    expect($this->user->onTrial())->toBeFalse();
});

it('returns false for onTrial when not subscribed', function () {
    expect($this->user->onTrial())->toBeFalse();
});

// ── onPlan ──────────────────────────────────────────────────────────

it('checks if subscribed to specific plan via onPlan', function () {
    app('tashil')->subscription()->subscribe($this->user, $this->package);

    expect($this->user->onPlan('pro-plan'))->toBeTrue();
    expect($this->user->onPlan('enterprise'))->toBeFalse();
});

// ── Feature access helpers (no subscription) ────────────────────────

it('returns false for hasFeature when not subscribed', function () {
    expect($this->user->hasFeature('api-requests'))->toBeFalse();
});

it('returns null for featureValue when not subscribed', function () {
    expect($this->user->featureValue('api-requests'))->toBeNull();
});

it('returns zero for featureUsage when not subscribed', function () {
    expect($this->user->featureUsage('api-requests'))->toBe(0);
});

it('returns null for featureRemaining when not subscribed', function () {
    expect($this->user->featureRemaining('api-requests'))->toBeNull();
});

// ── dailyUsageFor ───────────────────────────────────────────────────

it('returns empty array for dailyUsageFor when not subscribed', function () {
    expect($this->user->dailyUsageFor('api-requests'))->toBe([]);
});

it('returns empty array for dailyUsageFor when feature not found', function () {
    app('tashil')->subscription()->subscribe($this->user, $this->package);

    expect($this->user->dailyUsageFor('nonexistent-feature'))->toBe([]);
});

// ── useFeature without subscription ─────────────────────────────────

it('returns false for useFeature when not subscribed', function () {
    expect($this->user->useFeature('api-requests'))->toBeFalse();
});

// ── cancelSubscription / resumeSubscription / switchPlan (no sub) ───

it('returns null for cancelSubscription when not subscribed', function () {
    expect($this->user->cancelSubscription())->toBeNull();
});

it('returns null for resumeSubscription when no cancelled subscription', function () {
    expect($this->user->resumeSubscription())->toBeNull();
});

it('returns null for switchPlan when not subscribed', function () {
    $newPackage = Package::create([
        'name'             => 'Enterprise',
        'slug'             => 'enterprise',
        'price'            => 99.99,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    expect($this->user->switchPlan($newPackage))->toBeNull();
});
