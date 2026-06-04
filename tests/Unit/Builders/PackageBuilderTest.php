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

it('inherits requires_payment from the install-wide default when not pinned', function () {
    // The builder defers to Package::booted() (seeded from
    // tashil.billing.activate_on_payment) instead of hardcoding a value, so a
    // plan built under the off default activates immediately.
    config()->set('tashil.billing.activate_on_payment', false);
    expect((new PackageBuilder('legacy'))->price(30)->create()->requires_payment)->toBeFalse();

    config()->set('tashil.billing.activate_on_payment', true);
    expect((new PackageBuilder('gated'))->price(30)->create()->requires_payment)->toBeTrue();
});

it('pins requires_payment explicitly regardless of the install-wide default', function () {
    // An explicit requiresPayment() overrides the default in either direction.
    config()->set('tashil.billing.activate_on_payment', false);
    expect((new PackageBuilder('forced-gate'))->price(30)->requiresPayment(true)->create()->requires_payment)->toBeTrue();

    config()->set('tashil.billing.activate_on_payment', true);
    expect((new PackageBuilder('forced-free'))->price(30)->requiresPayment(false)->create()->requires_payment)->toBeFalse();
});

it('omits requires_payment from toArray() unless pinned, so the model seed and updates are not clobbered', function () {
    // Not pinned → key absent → Package::booted() seeds it / createOrUpdate
    // leaves an existing row's flag untouched.
    expect((new PackageBuilder('p'))->price(30)->toArray())->not->toHaveKey('requires_payment');

    // Pinned → key present with the chosen value.
    $arr = (new PackageBuilder('p'))->price(30)->requiresPayment(false)->toArray();
    expect($arr)->toHaveKey('requires_payment');
    expect($arr['requires_payment'])->toBeFalse();
});

it('createOrUpdate does not clobber an existing requires_payment when the caller did not pin one', function () {
    // Deliberately gated plan exists.
    $original = (new PackageBuilder('pro'))->price(30)->requiresPayment(true)->create();
    expect($original->requires_payment)->toBeTrue();

    // Flip the install-wide default AFTER the package was created. A later edit
    // that only changes price must NOT pull the new default into the row and
    // silently turn gating off. This guards against seeding the builder's
    // requires_payment from config eagerly (e.g. in the constructor / toArray),
    // which would emit the key on every createOrUpdate and clobber the flag.
    config()->set('tashil.billing.activate_on_payment', false);

    $updated = (new PackageBuilder('pro'))->price(40)->createOrUpdate();

    expect($updated->id)->toBe($original->id);
    expect($updated->price)->toBe('40.00');
    expect($updated->requires_payment)->toBeTrue();
});
