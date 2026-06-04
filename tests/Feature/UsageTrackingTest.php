<?php

use Foysal50x\Tashil\Events\UsageLimitWarning;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\FeatureUsage;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\UsageLog;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $this->limitFeature = Feature::create([
        'name' => 'API Requests',
        'slug' => 'api-requests',
        'type' => 'limit',
    ]);

    $this->booleanFeature = Feature::create([
        'name' => 'Email Support',
        'slug' => 'email-support',
        'type' => 'boolean',
    ]);

    $package = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 29.99,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    $package->features()->attach($this->limitFeature, ['value' => '100']);
    $package->features()->attach($this->booleanFeature, ['value' => 'true']);

    $user = createUser();
    $this->subscription = subscribeActive($user, $package);
});

function usageFor($subscription, string $slug): ?FeatureUsage
{
    return FeatureUsage::query()
        ->where('subscription_id', $subscription->id)
        ->whereHas('feature', fn ($q) => $q->where('slug', $slug))
        ->first();
}

it('increments usage for a limit feature', function () {
    $result = app('tashil')->usage()->increment($this->subscription, 'api-requests', 5);

    expect($result)->toBeTrue();
    expect(usageFor($this->subscription, 'api-requests')->usage)->toBe(5.0);
});

it('logs usage increments', function () {
    app('tashil')->usage()->increment($this->subscription, 'api-requests', 10);

    expect(UsageLog::count())->toBe(1);

    $log = UsageLog::first();
    expect($log->subscription_id)->toBe($this->subscription->id);
    expect($log->feature_id)->toBe($this->limitFeature->id);
    expect((float) $log->amount)->toBe(10.0);
    expect((float) $log->previous_usage)->toBe(0.0);
    expect((float) $log->new_usage)->toBe(10.0);
});

it('rejects usage exceeding limit atomically', function () {
    app('tashil')->usage()->increment($this->subscription, 'api-requests', 95);

    $result = app('tashil')->usage()->increment($this->subscription, 'api-requests', 10);

    expect($result)->toBeFalse();
    expect(usageFor($this->subscription, 'api-requests')->usage)->toBe(95.0);
});

it('dispatches warning when crossing 80% threshold', function () {
    Event::fake([UsageLimitWarning::class]);

    app('tashil')->usage()->increment($this->subscription, 'api-requests', 79);
    Event::assertNotDispatched(UsageLimitWarning::class);

    app('tashil')->usage()->increment($this->subscription, 'api-requests', 2);
    Event::assertDispatched(UsageLimitWarning::class);
});

it('checks feature access for boolean feature', function () {
    expect(app('tashil')->usage()->check($this->subscription, 'email-support'))->toBeTrue();
});

it('checks feature access for limit feature within quota', function () {
    app('tashil')->usage()->increment($this->subscription, 'api-requests', 50);

    expect(app('tashil')->usage()->check($this->subscription, 'api-requests'))->toBeTrue();
});

it('checks feature access rejects over-limit', function () {
    app('tashil')->usage()->increment($this->subscription, 'api-requests', 100);

    expect(app('tashil')->usage()->check($this->subscription, 'api-requests'))->toBeFalse();
});

it('returns false for unknown feature', function () {
    expect(app('tashil')->usage()->increment($this->subscription, 'nonexistent-feature', 1))->toBeFalse();
    expect(app('tashil')->usage()->check($this->subscription, 'nonexistent-feature'))->toBeFalse();
});

it('resets usage for a feature', function () {
    app('tashil')->usage()->increment($this->subscription, 'api-requests', 50);

    expect(app('tashil')->usage()->resetUsage($this->subscription, 'api-requests'))->toBeTrue();
    expect(usageFor($this->subscription, 'api-requests')->usage)->toBe(0.0);
});

it('resets all usage for a subscription', function () {
    app('tashil')->usage()->increment($this->subscription, 'api-requests', 50);

    app('tashil')->usage()->resetAllUsage($this->subscription);

    $this->subscription->featureUsages->each(function ($u) {
        expect($u->fresh()->usage)->toBe(0.0);
    });
});

it('rejects usage on inactive feature', function () {
    $this->limitFeature->update(['is_active' => false]);

    expect(app('tashil')->usage()->check($this->subscription, 'api-requests'))->toBeFalse();
});

it('reportStorage updates absolute usage', function () {
    $storage = Feature::create([
        'name' => 'Storage',
        'slug' => 'storage-gb',
        'type' => 'limit',
    ]);

    $package = Package::create([
        'name'             => 'Storage Plan',
        'slug'             => 'storage-plan',
        'price'            => 10.00,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);
    $package->features()->attach($storage, ['value' => '1000']);

    $user = createUser();
    $sub = subscribeActive($user, $package);

    app('tashil')->usage()->reportStorage($sub, 'storage-gb', 250);
    expect(usageFor($sub, 'storage-gb')->usage)->toBe(250.0);

    app('tashil')->usage()->reportStorage($sub, 'storage-gb', 150);
    expect(usageFor($sub, 'storage-gb')->usage)->toBe(150.0);
});
