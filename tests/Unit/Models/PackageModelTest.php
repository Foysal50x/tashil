<?php

use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;

beforeEach(function () {
    $this->package = Package::create([
        'name'             => 'Pro Plan',
        'slug'             => 'pro-plan',
        'description'      => 'Professional plan',
        'price'            => 29.99,
        'original_price'   => 39.99,
        'currency'         => 'USD',
        'billing_period'   => 'month',
        'billing_interval' => 1,
        'trial_days'       => 14,
        'is_active'        => true,
        'is_featured'      => true,
        'sort_order'       => 1,
        'metadata'         => ['color' => 'blue'],
    ]);
});

it('creates a package with correct attributes', function () {
    expect($this->package->name)->toBe('Pro Plan');
    expect($this->package->slug)->toBe('pro-plan');
    expect($this->package->price)->toBe('29.99');
    expect($this->package->original_price)->toBe('39.99');
    expect($this->package->currency)->toBe('USD');
    expect($this->package->billing_period)->toBe(Period::Month);
    expect($this->package->billing_interval)->toBe(1);
    expect($this->package->trial_days)->toBe(14);
    expect($this->package->is_active)->toBeTrue();
    expect($this->package->is_featured)->toBeTrue();
    expect($this->package->sort_order)->toBe(1);
    expect($this->package->metadata)->toBe(['color' => 'blue']);
});

it('casts billing_period to Period enum', function () {
    expect($this->package->billing_period)->toBeInstanceOf(Period::class);
    expect($this->package->billing_period)->toBe(Period::Month);
});

it('scopes to active packages', function () {
    Package::create(['name' => 'Inactive', 'slug' => 'inactive', 'is_active' => false]);

    expect(Package::active()->count())->toBe(1);
    expect(Package::active()->first()->slug)->toBe('pro-plan');
});

it('scopes to featured packages', function () {
    Package::create(['name' => 'Basic', 'slug' => 'basic', 'is_featured' => false]);

    expect(Package::featured()->count())->toBe(1);
    expect(Package::featured()->first()->slug)->toBe('pro-plan');
});

it('scopes ordered by sort_order', function () {
    Package::create(['name' => 'Enterprise', 'slug' => 'enterprise', 'sort_order' => 0]);

    $ordered = Package::ordered()->pluck('slug')->toArray();
    expect($ordered)->toBe(['enterprise', 'pro-plan']);
});

it('has features relationship via pivot', function () {
    $feature = Feature::create([
        'name' => 'API Requests',
        'slug' => 'api-requests',
        'type' => 'limit',
    ]);

    $this->package->features()->attach($feature, [
        'value'        => '10000',
        'is_available' => true,
        'sort_order'   => 1,
    ]);

    expect($this->package->features)->toHaveCount(1);
    expect($this->package->features->first()->pivot->value)->toBe('10000');
});

it('has subscriptions relationship', function () {
    expect($this->package->subscriptions()->getRelated())->toBeInstanceOf(\Foysal50x\Tashil\Models\Subscription::class);
});

it('uses soft deletes', function () {
    $this->package->delete();

    expect(Package::count())->toBe(0);
    expect(Package::withTrashed()->count())->toBe(1);
});
