<?php

use Foysal50x\Tashil\Builders\FeatureBuilder;
use Foysal50x\Tashil\Enums\FeatureType;
use Foysal50x\Tashil\Models\Feature;

it('creates a feature via builder', function () {
    $feature = (new FeatureBuilder('api-requests'))
        ->name('API Requests')
        ->description('Monthly API request limit')
        ->type(FeatureType::Limit)
        ->sortOrder(1)
        ->metadata(['reset' => 'monthly'])
        ->create();

    expect($feature)->toBeInstanceOf(Feature::class);
    expect($feature->exists)->toBeTrue();
    expect($feature->slug)->toBe('api-requests');
    expect($feature->name)->toBe('API Requests');
    expect($feature->description)->toBe('Monthly API request limit');
    expect($feature->type)->toBe(FeatureType::Limit);
    expect($feature->sort_order)->toBe(1);
    expect($feature->metadata)->toBe(['reset' => 'monthly']);
});

it('has shorthand type helpers', function () {
    $boolean = (new FeatureBuilder('email-support'))
        ->name('Email Support')
        ->boolean()
        ->create();

    $limit = (new FeatureBuilder('api-requests'))
        ->name('API Requests')
        ->limit()
        ->create();

    $consumable = (new FeatureBuilder('storage'))
        ->name('Storage')
        ->consumable()
        ->create();

    expect($boolean->type)->toBe(FeatureType::Boolean);
    expect($limit->type)->toBe(FeatureType::Limit);
    expect($consumable->type)->toBe(FeatureType::Consumable);
});

it('defaults to boolean type', function () {
    $feature = (new FeatureBuilder('some-toggle'))
        ->name('Some Toggle')
        ->create();

    expect($feature->type)->toBe(FeatureType::Boolean);
});

it('defaults name to slug', function () {
    $feature = (new FeatureBuilder('my-feature'))->create();
    expect($feature->name)->toBe('my-feature');
});

it('creates or updates a feature', function () {
    $feature1 = (new FeatureBuilder('api-requests'))
        ->name('API Requests')
        ->limit()
        ->create();

    $feature2 = (new FeatureBuilder('api-requests'))
        ->name('API Requests Updated')
        ->limit()
        ->createOrUpdate();

    expect(Feature::count())->toBe(1);
    expect($feature2->id)->toBe($feature1->id);
    expect($feature2->name)->toBe('API Requests Updated');
});

it('can set inactive', function () {
    $feature = (new FeatureBuilder('disabled'))
        ->name('Disabled Feature')
        ->inactive()
        ->create();

    expect($feature->is_active)->toBeFalse();
});

it('returns array representation', function () {
    $builder = (new FeatureBuilder('api-requests'))
        ->name('API Requests')
        ->limit()
        ->sortOrder(5);

    $arr = $builder->toArray();

    expect($arr)->toHaveKeys(['slug', 'name', 'type', 'sort_order', 'is_active']);
    expect($arr['slug'])->toBe('api-requests');
    expect($arr['type'])->toBe(FeatureType::Limit);
    expect($arr['sort_order'])->toBe(5);
});
