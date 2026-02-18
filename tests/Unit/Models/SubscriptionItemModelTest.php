<?php

use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionItem;

beforeEach(function () {
    $this->package = Package::create(['name' => 'Pro', 'slug' => 'pro', 'price' => 29.99]);
    $this->feature = Feature::create(['name' => 'API Requests', 'slug' => 'api-requests', 'type' => 'limit']);
    $this->subscription = Subscription::create([
        'subscriber_type' => 'App\\Models\\User',
        'subscriber_id'   => 1,
        'package_id'      => $this->package->id,
        'status'          => 'active',
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);
    $this->item = SubscriptionItem::create([
        'subscription_id' => $this->subscription->id,
        'feature_id'      => $this->feature->id,
        'value'           => '1000',
        'usage'           => 0,
    ]);
});

it('creates a subscription item with correct attributes', function () {
    expect($this->item->value)->toBe('1000');
    expect($this->item->usage)->toBe(0);
});

it('has subscription relationship', function () {
    expect($this->item->subscription->id)->toBe($this->subscription->id);
});

it('has feature relationship', function () {
    expect($this->item->feature->slug)->toBe('api-requests');
});

it('detects over limit correctly', function () {
    expect($this->item->overLimit())->toBeFalse();

    $this->item->update(['usage' => 999]);
    expect($this->item->overLimit())->toBeFalse();
    expect($this->item->overLimit(2))->toBeTrue();

    $this->item->update(['usage' => 1001]);
    expect($this->item->overLimit())->toBeTrue();
});

it('returns remaining correctly', function () {
    expect($this->item->remaining())->toBe(1000.0);

    $this->item->update(['usage' => 750]);
    expect($this->item->remaining())->toBe(250.0);

    $this->item->update(['usage' => 1200]);
    expect($this->item->remaining())->toBe(0.0);
});

it('returns null remaining for unlimited (null value)', function () {
    $this->item->update(['value' => null]);
    expect($this->item->remaining())->toBeNull();
});

it('calculates usage percentage', function () {
    expect($this->item->usagePercentage())->toBe(0.0);

    $this->item->update(['usage' => 500]);
    expect($this->item->usagePercentage())->toBe(50.0);

    $this->item->update(['usage' => 800]);
    expect($this->item->usagePercentage())->toBe(80.0);

    $this->item->update(['usage' => 1000]);
    expect($this->item->usagePercentage())->toBe(100.0);
});

it('returns null percentage for unlimited (null value)', function () {
    $this->item->update(['value' => null]);
    expect($this->item->usagePercentage())->toBeNull();
});

it('handles not over limit for null value (unlimited)', function () {
    $this->item->update(['value' => null]);
    expect($this->item->overLimit())->toBeFalse();
    expect($this->item->overLimit(999999))->toBeFalse();
});
