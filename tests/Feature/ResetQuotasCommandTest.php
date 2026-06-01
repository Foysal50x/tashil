<?php

use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\FeatureUsage;
use Foysal50x\Tashil\Models\Package;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $feature = Feature::create([
        'name'         => 'API',
        'slug'         => 'api',
        'type'         => 'limit',
        'reset_period' => 'monthly',
    ]);

    $package = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 10,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);
    $package->features()->attach($feature, ['value' => '100']);

    $this->user = createUser();
    $this->sub = Tashil::subscription()->subscribe($this->user, $package);

    expect(Tashil::usage()->increment($this->sub, 'api', 5))->toBeTrue();
    expect((float) FeatureUsage::where('subscription_id', $this->sub->id)->first()->usage)->toBe(5.0);
});

it('tashil:reset-quotas zeroes a counter whose period has elapsed and advances the window', function () {
    // Simulate the period having ended.
    FeatureUsage::where('subscription_id', $this->sub->id)->update([
        'period_end' => now()->subDay(),
    ]);

    $this->artisan('tashil:reset-quotas')->assertSuccessful();

    $usage = FeatureUsage::where('subscription_id', $this->sub->id)->first();
    expect((float) $usage->usage)->toBe(0.0);
    expect($usage->period_end->isFuture())->toBeTrue();
});

it('tashil:reset-quotas leaves counters whose period has not elapsed untouched', function () {
    // period_end is still in the future (set on subscribe), so nothing is due.
    $this->artisan('tashil:reset-quotas')->assertSuccessful();

    expect((float) FeatureUsage::where('subscription_id', $this->sub->id)->first()->usage)->toBe(5.0);
});
