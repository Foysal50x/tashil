<?php

use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../../Fixtures/create_users_table.php');

    $this->user = createUser();

    $this->package = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 29.99,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);
});

it('detects suspended subscription', function () {
    $subscription = Subscription::create([
        'subscriber_type' => get_class($this->user),
        'subscriber_id'   => $this->user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Suspended,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    expect($subscription->isSuspended())->toBeTrue();
    expect($subscription->isActive())->toBeFalse();
    expect($subscription->isValid())->toBeFalse();
});

it('detects past due subscription', function () {
    $subscription = Subscription::create([
        'subscriber_type' => get_class($this->user),
        'subscriber_id'   => $this->user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::PastDue,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    expect($subscription->isPastDue())->toBeTrue();
    expect($subscription->isActive())->toBeFalse();
    expect($subscription->isValid())->toBeFalse();
});

it('detects expired subscription by past ends_at date', function () {
    $subscription = Subscription::create([
        'subscriber_type' => get_class($this->user),
        'subscriber_id'   => $this->user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now()->subMonths(2),
        'ends_at'         => now()->subDay(),
    ]);

    // Active status but ends_at is past — isActive() should be false
    expect($subscription->isActive())->toBeFalse();
    // isExpired() checks: status is expired OR (ends_at past AND not cancelled)
    expect($subscription->isExpired())->toBeTrue();
});

it('detects on trial via trial_ends_at even with active status', function () {
    $subscription = Subscription::create([
        'subscriber_type' => get_class($this->user),
        'subscriber_id'   => $this->user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
        'trial_ends_at'   => now()->addDays(7),
    ]);

    // isOnTrial() checks status == OnTrial OR trial_ends_at is future
    expect($subscription->isOnTrial())->toBeTrue();
    expect($subscription->isValid())->toBeTrue();
});

it('detects cancelled via cancelled_at even with different status', function () {
    $subscription = Subscription::create([
        'subscriber_type' => get_class($this->user),
        'subscriber_id'   => $this->user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
        'cancelled_at'    => now(),
    ]);

    // isCancelled() checks status == Cancelled OR cancelled_at is not null
    expect($subscription->isCancelled())->toBeTrue();
});

it('not expired returns false for non-suspended non-past status', function () {
    $subscription = Subscription::create([
        'subscriber_type' => get_class($this->user),
        'subscriber_id'   => $this->user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Suspended,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    expect($subscription->isSuspended())->toBeTrue();
    expect($subscription->isPastDue())->toBeFalse();
    expect($subscription->isExpired())->toBeFalse();
});

it('active with null ends_at is valid', function () {
    $subscription = Subscription::create([
        'subscriber_type' => get_class($this->user),
        'subscriber_id'   => $this->user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => null,
    ]);

    expect($subscription->isActive())->toBeTrue();
    expect($subscription->isValid())->toBeTrue();
    expect($subscription->isExpired())->toBeFalse();
});
