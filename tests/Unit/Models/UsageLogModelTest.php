<?php

use Foysal50x\Tashil\Enums\FeatureType;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\UsageLog;

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

    $this->feature = Feature::create([
        'name' => 'API Requests',
        'slug' => 'api-requests',
        'type' => FeatureType::Limit,
    ]);

    $this->subscription = Subscription::create([
        'subscriber_type' => get_class($this->user),
        'subscriber_id'   => $this->user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);
});

it('creates a usage log with correct attributes', function () {
    $log = UsageLog::create([
        'subscription_id' => $this->subscription->id,
        'feature_id'      => $this->feature->id,
        'amount'          => 5.50,
        'metadata'        => ['ip' => '127.0.0.1'],
    ]);

    expect($log->subscription_id)->toBe($this->subscription->id);
    expect($log->feature_id)->toBe($this->feature->id);
    expect((float) $log->amount)->toBe(5.50);
    expect($log->metadata)->toBe(['ip' => '127.0.0.1']);
});

it('casts amount to decimal', function () {
    $log = UsageLog::create([
        'subscription_id' => $this->subscription->id,
        'feature_id'      => $this->feature->id,
        'amount'          => 3,
    ]);

    expect($log->amount)->toBeString(); // decimal cast returns string
    expect((float) $log->amount)->toBe(3.00);
});

it('casts metadata to array', function () {
    $log = UsageLog::create([
        'subscription_id' => $this->subscription->id,
        'feature_id'      => $this->feature->id,
        'amount'          => 1,
        'metadata'        => ['action' => 'api_call', 'endpoint' => '/users'],
    ]);

    expect($log->metadata)->toBeArray();
    expect($log->metadata['action'])->toBe('api_call');
});

it('has subscription relationship', function () {
    $log = UsageLog::create([
        'subscription_id' => $this->subscription->id,
        'feature_id'      => $this->feature->id,
        'amount'          => 1,
    ]);

    expect($log->subscription)->toBeInstanceOf(Subscription::class);
    expect($log->subscription->id)->toBe($this->subscription->id);
});

it('has feature relationship', function () {
    $log = UsageLog::create([
        'subscription_id' => $this->subscription->id,
        'feature_id'      => $this->feature->id,
        'amount'          => 1,
    ]);

    expect($log->feature)->toBeInstanceOf(Feature::class);
    expect($log->feature->id)->toBe($this->feature->id);
});
