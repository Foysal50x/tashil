<?php

use Foysal50x\Tashil\Events\UsageLimitWarning;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionItem;
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
    $this->subscription = app('tashil')->subscription()->subscribe($user, $package);
});

it('increments usage for a limit feature', function () {
    $result = app('tashil')->usage()->increment($this->subscription, 'api-requests', 5);

    expect($result)->toBeTrue();

    $item = $this->subscription->items()
        ->whereHas('feature', fn ($q) => $q->where('slug', 'api-requests'))
        ->first();

    expect($item->usage)->toBe(5);
});

it('logs usage increments', function () {
    app('tashil')->usage()->increment($this->subscription, 'api-requests', 10);

    expect(UsageLog::count())->toBe(1);

    $log = UsageLog::first();
    expect($log->subscription_id)->toBe($this->subscription->id);
    expect($log->feature_id)->toBe($this->limitFeature->id);
    expect($log->amount)->toBe('10.00');
});

it('rejects usage exceeding limit', function () {
    // Use 95 of 100
    app('tashil')->usage()->increment($this->subscription, 'api-requests', 95);

    // Try to use 10 more — should fail
    $result = app('tashil')->usage()->increment($this->subscription, 'api-requests', 10);

    expect($result)->toBeFalse();
});

it('dispatches warning at 80% usage', function () {
    Event::fake([UsageLimitWarning::class]);

    // Use 79 — no warning
    app('tashil')->usage()->increment($this->subscription, 'api-requests', 79);
    Event::assertNotDispatched(UsageLimitWarning::class);

    // Use 2 more (81 total) — crosses 80% threshold
    app('tashil')->usage()->increment($this->subscription, 'api-requests', 2);
    Event::assertDispatched(UsageLimitWarning::class);
});

it('checks feature access for boolean feature', function () {
    $result = app('tashil')->usage()->check($this->subscription, 'email-support');
    expect($result)->toBeTrue();
});

it('checks feature access for limit feature within quota', function () {
    app('tashil')->usage()->increment($this->subscription, 'api-requests', 50);

    $result = app('tashil')->usage()->check($this->subscription, 'api-requests');
    expect($result)->toBeTrue();
});

it('checks feature access rejects over-limit', function () {
    app('tashil')->usage()->increment($this->subscription, 'api-requests', 100);

    $result = app('tashil')->usage()->check($this->subscription, 'api-requests');
    expect($result)->toBeFalse();
});

it('returns false for unknown feature', function () {
    $result = app('tashil')->usage()->increment($this->subscription, 'nonexistent-feature', 1);
    expect($result)->toBeFalse();

    $check = app('tashil')->usage()->check($this->subscription, 'nonexistent-feature');
    expect($check)->toBeFalse();
});

it('resets usage for a feature', function () {
    app('tashil')->usage()->increment($this->subscription, 'api-requests', 50);

    $result = app('tashil')->usage()->resetUsage($this->subscription, 'api-requests');

    expect($result)->toBeTrue();

    $item = $this->subscription->items()
        ->whereHas('feature', fn ($q) => $q->where('slug', 'api-requests'))
        ->first();

    expect($item->usage)->toBe(0);
});

it('resets all usage for a subscription', function () {
    app('tashil')->usage()->increment($this->subscription, 'api-requests', 50);

    app('tashil')->usage()->resetAllUsage($this->subscription);

    $this->subscription->items->each(function ($item) {
        expect($item->fresh()->usage)->toBe(0);
    });
});

it('rejects usage on inactive feature', function () {
    $this->limitFeature->update(['is_active' => false]);

    $result = app('tashil')->usage()->check($this->subscription, 'api-requests');
    expect($result)->toBeFalse();
});
