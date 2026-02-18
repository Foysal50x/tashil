<?php

use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;

beforeEach(function () {
    $this->package = Package::create([
        'name'  => 'Pro',
        'slug'  => 'pro',
        'price' => 29.99,
    ]);
});

it('creates a subscription with correct attributes', function () {
    $sub = Subscription::create([
        'subscriber_type' => 'App\\Models\\User',
        'subscriber_id'   => 1,
        'package_id'      => $this->package->id,
        'status'          => 'active',
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
        'auto_renew'      => true,
        'metadata'        => ['source' => 'web'],
    ]);

    expect($sub->status)->toBe(SubscriptionStatus::Active);
    expect($sub->auto_renew)->toBeTrue();
    expect($sub->metadata)->toBe(['source' => 'web']);
    expect($sub->starts_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
    expect($sub->ends_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

it('casts status to SubscriptionStatus enum', function () {
    $sub = Subscription::create([
        'subscriber_type' => 'App\\Models\\User',
        'subscriber_id'   => 1,
        'package_id'      => $this->package->id,
        'status'          => 'on_trial',
        'starts_at'       => now(),
    ]);

    expect($sub->status)->toBe(SubscriptionStatus::OnTrial);
});

it('detects active subscription', function () {
    $sub = Subscription::create([
        'subscriber_type' => 'App\\Models\\User',
        'subscriber_id'   => 1,
        'package_id'      => $this->package->id,
        'status'          => 'active',
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    expect($sub->isActive())->toBeTrue();
});

it('detects inactive subscription when expired', function () {
    $sub = Subscription::create([
        'subscriber_type' => 'App\\Models\\User',
        'subscriber_id'   => 1,
        'package_id'      => $this->package->id,
        'status'          => 'active',
        'starts_at'       => now()->subMonth(),
        'ends_at'         => now()->subDay(),
    ]);

    expect($sub->isActive())->toBeFalse();
});

it('detects trial subscription', function () {
    $sub = Subscription::create([
        'subscriber_type' => 'App\\Models\\User',
        'subscriber_id'   => 1,
        'package_id'      => $this->package->id,
        'status'          => 'on_trial',
        'starts_at'       => now(),
        'trial_ends_at'   => now()->addDays(14),
    ]);

    expect($sub->isOnTrial())->toBeTrue();
});

it('detects cancelled subscription', function () {
    $sub = Subscription::create([
        'subscriber_type' => 'App\\Models\\User',
        'subscriber_id'   => 1,
        'package_id'      => $this->package->id,
        'status'          => 'cancelled',
        'starts_at'       => now(),
        'cancelled_at'    => now(),
        'cancellation_reason' => 'Too expensive',
    ]);

    expect($sub->isCancelled())->toBeTrue();
    expect($sub->cancellation_reason)->toBe('Too expensive');
});

it('detects valid subscription (active or trial)', function () {
    $active = Subscription::create([
        'subscriber_type' => 'App\\Models\\User',
        'subscriber_id'   => 1,
        'package_id'      => $this->package->id,
        'status'          => 'active',
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    $trial = Subscription::create([
        'subscriber_type' => 'App\\Models\\User',
        'subscriber_id'   => 2,
        'package_id'      => $this->package->id,
        'status'          => 'on_trial',
        'starts_at'       => now(),
        'trial_ends_at'   => now()->addDays(14),
    ]);

    expect($active->isValid())->toBeTrue();
    expect($trial->isValid())->toBeTrue();
});

it('has status scopes', function () {
    Subscription::create([
        'subscriber_type' => 'App\\Models\\User',
        'subscriber_id'   => 1,
        'package_id'      => $this->package->id,
        'status'          => 'active',
        'starts_at'       => now(),
    ]);

    Subscription::create([
        'subscriber_type' => 'App\\Models\\User',
        'subscriber_id'   => 2,
        'package_id'      => $this->package->id,
        'status'          => 'cancelled',
        'starts_at'       => now(),
        'cancelled_at'    => now(),
    ]);

    Subscription::create([
        'subscriber_type' => 'App\\Models\\User',
        'subscriber_id'   => 3,
        'package_id'      => $this->package->id,
        'status'          => 'on_trial',
        'starts_at'       => now(),
    ]);

    expect(Subscription::active()->count())->toBe(1);
    expect(Subscription::cancelled()->count())->toBe(1);
    expect(Subscription::onTrial()->count())->toBe(1);
    expect(Subscription::valid()->count())->toBe(2);
});

it('has package relationship', function () {
    $sub = Subscription::create([
        'subscriber_type' => 'App\\Models\\User',
        'subscriber_id'   => 1,
        'package_id'      => $this->package->id,
        'status'          => 'active',
        'starts_at'       => now(),
    ]);

    expect($sub->package->slug)->toBe('pro');
});

it('uses soft deletes', function () {
    $sub = Subscription::create([
        'subscriber_type' => 'App\\Models\\User',
        'subscriber_id'   => 1,
        'package_id'      => $this->package->id,
        'status'          => 'active',
        'starts_at'       => now(),
    ]);

    $sub->delete();

    expect(Subscription::count())->toBe(0);
    expect(Subscription::withTrashed()->count())->toBe(1);
});
