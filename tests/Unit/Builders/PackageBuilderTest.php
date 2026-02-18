<?php

use Foysal50x\Tashil\Builders\PackageBuilder;
use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;

it('creates a package via builder', function () {
    $package = (new PackageBuilder('pro-plan'))
        ->name('Pro Plan')
        ->description('Professional tier')
        ->price(29.99)
        ->originalPrice(39.99)
        ->currency('EUR')
        ->billingPeriod(Period::Month, 1)
        ->trialDays(14)
        ->featured()
        ->sortOrder(2)
        ->metadata(['badge' => 'popular'])
        ->create();

    expect($package)->toBeInstanceOf(Package::class);
    expect($package->exists)->toBeTrue();
    expect($package->slug)->toBe('pro-plan');
    expect($package->name)->toBe('Pro Plan');
    expect($package->price)->toBe('29.99');
    expect($package->original_price)->toBe('39.99');
    expect($package->currency)->toBe('EUR');
    expect($package->billing_period)->toBe(Period::Month);
    expect($package->billing_interval)->toBe(1);
    expect($package->trial_days)->toBe(14);
    expect($package->is_featured)->toBeTrue();
    expect($package->sort_order)->toBe(2);
    expect($package->metadata)->toBe(['badge' => 'popular']);
});

it('has billing period shorthands', function () {
    $monthly = (new PackageBuilder('monthly'))->monthly()->create();
    expect($monthly->billing_period)->toBe(Period::Month);
    expect($monthly->billing_interval)->toBe(1);

    $quarterly = (new PackageBuilder('quarterly'))->quarterly()->create();
    expect($quarterly->billing_period)->toBe(Period::Month);
    expect($quarterly->billing_interval)->toBe(3);

    $yearly = (new PackageBuilder('yearly'))->yearly()->create();
    expect($yearly->billing_period)->toBe(Period::Year);
    expect($yearly->billing_interval)->toBe(1);

    $lifetime = (new PackageBuilder('lifetime'))->lifetime()->create();
    expect($lifetime->billing_period)->toBe(Period::Lifetime);
    expect($lifetime->billing_interval)->toBe(1);
});

it('attaches a single feature to a package', function () {
    $feature = Feature::create(['name' => 'API Requests', 'slug' => 'api-requests', 'type' => 'limit']);

    $package = (new PackageBuilder('pro'))
        ->name('Pro Plan')
        ->price(29.99)
        ->feature($feature, value: '10000')
        ->create();

    expect($package->features)->toHaveCount(1);
    expect($package->features->first()->slug)->toBe('api-requests');
    expect($package->features->first()->pivot->value)->toBe('10000');
});

it('attaches multiple features to a package', function () {
    $f1 = Feature::create(['name' => 'API Requests', 'slug' => 'api-requests', 'type' => 'limit']);
    $f2 = Feature::create(['name' => 'Email Support', 'slug' => 'email-support', 'type' => 'boolean']);
    $f3 = Feature::create(['name' => 'Storage', 'slug' => 'storage', 'type' => 'consumable']);

    $package = (new PackageBuilder('pro'))
        ->name('Pro Plan')
        ->feature($f1, value: '10000')
        ->feature($f2, value: 'true')
        ->feature($f3, value: '5120')
        ->create();

    expect($package->features)->toHaveCount(3);
});

it('attaches features via bulk features() method', function () {
    $f1 = Feature::create(['name' => 'API', 'slug' => 'api', 'type' => 'limit']);
    $f2 = Feature::create(['name' => 'Support', 'slug' => 'support', 'type' => 'boolean']);

    $package = (new PackageBuilder('pro'))
        ->features([$f1, $f2])
        ->create();

    expect($package->features)->toHaveCount(2);
});

it('creates or updates a package', function () {
    $p1 = (new PackageBuilder('pro'))
        ->name('Pro Plan')
        ->price(29.99)
        ->create();

    $p2 = (new PackageBuilder('pro'))
        ->name('Pro Plan v2')
        ->price(39.99)
        ->createOrUpdate();

    expect(Package::count())->toBe(1);
    expect($p2->id)->toBe($p1->id);
    expect($p2->name)->toBe('Pro Plan v2');
    expect($p2->price)->toBe('39.99');
});

it('uses default currency from config', function () {
    $package = (new PackageBuilder('basic'))
        ->name('Basic')
        ->create();

    expect($package->currency)->toBe('USD'); // default from config
});

it('returns array representation', function () {
    $builder = (new PackageBuilder('pro'))
        ->name('Pro Plan')
        ->price(29.99)
        ->monthly();

    $arr = $builder->toArray();

    expect($arr)->toHaveKeys(['slug', 'name', 'price', 'billing_period', 'billing_interval']);
    expect($arr['slug'])->toBe('pro');
    expect($arr['price'])->toBe(29.99);
});
