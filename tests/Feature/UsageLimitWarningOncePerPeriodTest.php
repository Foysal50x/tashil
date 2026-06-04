<?php

use Foysal50x\Tashil\Events\UsageLimitWarning;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $feature = Feature::create([
        'name' => 'API',
        'slug' => 'api',
        'type' => 'limit',
    ]);

    $package = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 10,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);
    $package->features()->attach($feature, ['value' => '10']);

    $this->user = createUser();
    $this->sub = subscribeActive($this->user, $package);
});

it('fires UsageLimitWarning exactly once when usage first crosses 80%, not on every subsequent increment', function () {
    Event::fake([UsageLimitWarning::class]);

    // 0 -> 8 crosses the 80% threshold (8 of 10): fires once.
    expect(Tashil::usage()->increment($this->sub, 'api', 8))->toBeTrue();
    // 8 -> 9 stays above the threshold: must NOT fire again this period.
    expect(Tashil::usage()->increment($this->sub, 'api', 1))->toBeTrue();

    Event::assertDispatchedTimes(UsageLimitWarning::class, 1);
});

it('does not fire UsageLimitWarning while usage stays below 80%', function () {
    Event::fake([UsageLimitWarning::class]);

    expect(Tashil::usage()->increment($this->sub, 'api', 7))->toBeTrue();

    Event::assertNotDispatched(UsageLimitWarning::class);
});
