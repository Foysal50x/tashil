<?php

use Foysal50x\Tashil\Enums\FeatureType;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;

beforeEach(function () {
    $this->feature = Feature::create([
        'name'        => 'API Requests',
        'slug'        => 'api-requests',
        'description' => 'Monthly API request limit',
        'type'        => 'limit',
        'is_active'   => true,
        'sort_order'  => 1,
        'metadata'    => ['reset' => 'monthly'],
    ]);
});

it('creates a feature with correct attributes', function () {
    expect($this->feature->name)->toBe('API Requests');
    expect($this->feature->slug)->toBe('api-requests');
    expect($this->feature->type)->toBe(FeatureType::Limit);
    expect($this->feature->is_active)->toBeTrue();
    expect($this->feature->sort_order)->toBe(1);
    expect($this->feature->metadata)->toBe(['reset' => 'monthly']);
});

it('casts type to FeatureType enum', function () {
    expect($this->feature->type)->toBeInstanceOf(FeatureType::class);
});

it('has type-checking helpers', function () {
    expect($this->feature->isLimit())->toBeTrue();
    expect($this->feature->isBoolean())->toBeFalse();
    expect($this->feature->isConsumable())->toBeFalse();

    $booleanFeature = Feature::create([
        'name' => 'Email Support',
        'slug' => 'email-support',
        'type' => 'boolean',
    ]);
    expect($booleanFeature->isBoolean())->toBeTrue();
    expect($booleanFeature->isLimit())->toBeFalse();

    $consumable = Feature::create([
        'name' => 'Storage',
        'slug' => 'storage',
        'type' => 'consumable',
    ]);
    expect($consumable->isConsumable())->toBeTrue();
});

it('scopes to active features', function () {
    Feature::create(['name' => 'Disabled', 'slug' => 'disabled', 'is_active' => false]);

    expect(Feature::active()->count())->toBe(1);
    expect(Feature::active()->first()->slug)->toBe('api-requests');
});

it('scopes ordered by sort_order', function () {
    Feature::create(['name' => 'First', 'slug' => 'first', 'sort_order' => 0]);

    $ordered = Feature::ordered()->pluck('slug')->toArray();
    expect($ordered)->toBe(['first', 'api-requests']);
});

it('has inverse packages relationship', function () {
    $package = Package::create([
        'name' => 'Pro',
        'slug' => 'pro',
        'price' => 29.99,
    ]);

    $this->feature->packages()->attach($package, ['value' => '5000']);

    expect($this->feature->packages)->toHaveCount(1);
    expect($this->feature->packages->first()->slug)->toBe('pro');
});

it('uses soft deletes', function () {
    $this->feature->delete();

    expect(Feature::count())->toBe(0);
    expect(Feature::withTrashed()->count())->toBe(1);
});
