<?php

declare(strict_types=1);

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

it('detects past due subscription and gates access by the dunning config', function () {
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

    // Soft dunning (default): past_due retains access during the retry window.
    config()->set('tashil.dunning.keep_access_while_past_due', true);
    expect($subscription->isValid())->toBeTrue();

    // Hard dunning: access is cut immediately on past_due.
    config()->set('tashil.dunning.keep_access_while_past_due', false);
    expect($subscription->isValid())->toBeFalse();
});

it('isActive is false when ends_at is past', function () {
    $subscription = Subscription::create([
        'subscriber_type' => get_class($this->user),
        'subscriber_id'   => $this->user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now()->subMonths(2),
        'ends_at'         => now()->subDay(),
    ]);

    expect($subscription->isActive())->toBeFalse();
});

it('isExpired requires explicit Expired status', function () {
    $stillActiveButPastEnd = Subscription::create([
        'subscriber_type' => get_class($this->user),
        'subscriber_id'   => $this->user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now()->subMonths(2),
        'ends_at'         => now()->subDay(),
    ]);

    expect($stillActiveButPastEnd->isExpired())->toBeFalse();

    $expired = Subscription::create([
        'subscriber_type' => get_class($this->user),
        'subscriber_id'   => $this->user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Expired,
        'starts_at'       => now()->subMonths(2),
        'ends_at'         => now()->subDay(),
    ]);

    expect($expired->isExpired())->toBeTrue();
});

it('isOnTrial requires OnTrial status AND future trial_ends_at', function () {
    $activeWithFutureTrial = Subscription::create([
        'subscriber_type' => get_class($this->user),
        'subscriber_id'   => $this->user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
        'trial_ends_at'   => now()->addDays(7),
    ]);

    expect($activeWithFutureTrial->isOnTrial())->toBeFalse();

    $onTrial = Subscription::create([
        'subscriber_type' => get_class($this->user),
        'subscriber_id'   => $this->user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::OnTrial,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
        'trial_ends_at'   => now()->addDays(7),
    ]);

    expect($onTrial->isOnTrial())->toBeTrue();
});

it('isCancelled requires explicit Cancelled status', function () {
    $graceCancelled = Subscription::create([
        'subscriber_type' => get_class($this->user),
        'subscriber_id'   => $this->user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::PendingCancellation,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
        'cancelled_at'    => now(),
    ]);

    // PendingCancellation is not Cancelled — user still has access during grace.
    expect($graceCancelled->isCancelled())->toBeFalse();
    expect($graceCancelled->isPendingCancellation())->toBeTrue();
    expect($graceCancelled->isValid())->toBeTrue();

    $cancelled = Subscription::create([
        'subscriber_type' => get_class($this->user),
        'subscriber_id'   => $this->user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Cancelled,
        'starts_at'       => now(),
        'ends_at'         => now(),
        'cancelled_at'    => now(),
    ]);

    expect($cancelled->isCancelled())->toBeTrue();
});

it('paused is a distinct state', function () {
    $paused = Subscription::create([
        'subscriber_type' => get_class($this->user),
        'subscriber_id'   => $this->user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Paused,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    expect($paused->isPaused())->toBeTrue();
    expect($paused->isActive())->toBeFalse();
    expect($paused->isValid())->toBeFalse();
});

it('active with null ends_at is valid (lifetime)', function () {
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
