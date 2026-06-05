<?php

declare(strict_types=1);

use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Enums\UsageOperation;
use Foysal50x\Tashil\Exceptions\SubscriptionException;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\FeatureUsage;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\UsageLog;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');
    Carbon::setTestNow('2026-03-01 00:00:00');

    $this->api = Feature::create([
        'name'         => 'API',
        'slug'         => 'api',
        'type'         => 'limit',
        'reset_period' => 'monthly',
    ]);

    $this->plan = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 30.00,
        'billing_period'   => Period::Month,
        'billing_interval' => 1,
    ]);
    $this->plan->features()->attach($this->api, ['value' => '100']);

    $this->user = createUser();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('H2: pause banks the remaining access time and unpause adds it back', function () {
    $sub = subscribeActive($this->user, $this->plan); // ends_at = 2026-04-01
    expect($sub->ends_at->toDateString())->toBe('2026-04-01');

    // Pause 10 days in → 21 days remain (Mar 11 → Apr 1).
    $this->travelTo(Carbon::parse('2026-03-11'));
    Tashil::subscription()->pause($sub);

    // Resume after a long pause → the 21 banked days are added from now.
    $this->travelTo(Carbon::parse('2026-04-20'));
    $sub = Tashil::subscription()->unpause($sub->refresh());

    expect($sub->status)->toBe(SubscriptionStatus::Active);
    expect($sub->ends_at->toDateString())->toBe('2026-05-11'); // 2026-04-20 + 21 days
    expect($sub->isValid())->toBeTrue();
});

it('M1: advancePeriod is a no-op for a non-active (paused) subscription', function () {
    $sub = subscribeActive($this->user, $this->plan);
    Tashil::subscription()->pause($sub);
    $sub->refresh();
    $before = $sub->current_period_end?->toDateTimeString();

    $result = Tashil::subscription()->advancePeriod($sub);

    expect($result->status)->toBe(SubscriptionStatus::Paused);
    expect($result->current_period_end?->toDateTimeString())->toBe($before);
});

it('M2: resume restores auto_renew for a non-lifetime plan', function () {
    $sub = subscribeActive($this->user, $this->plan);
    Tashil::subscription()->cancel($sub); // grace → auto_renew false
    $sub->refresh();
    expect($sub->auto_renew)->toBeFalse();

    $resumed = Tashil::subscription()->resume($sub);
    expect($resumed->status)->toBe(SubscriptionStatus::Active);
    expect($resumed->auto_renew)->toBeTrue();
});

it('M2: resume does not re-enable auto_renew on a lifetime plan', function () {
    $lifetime = Package::create([
        'name'             => 'Lifetime',
        'slug'             => 'lifetime',
        'price'            => 500.00,
        'billing_period'   => Period::Lifetime,
        'billing_interval' => 1,
    ]);

    $sub = subscribeActive($this->user, $lifetime);
    expect($sub->auto_renew)->toBeFalse(); // lifetime never auto-renews

    Tashil::subscription()->cancel($sub); // grace
    $resumed = Tashil::subscription()->resume($sub->refresh());

    expect($resumed->status)->toBe(SubscriptionStatus::Active);
    expect($resumed->auto_renew)->toBeFalse();
});

it('H4: a lazy inline reset writes a reset log and usage.reset event so replay stays correct', function () {
    $sub = subscribeActive($this->user, $this->plan);
    Tashil::usage()->increment($sub, 'api', 50);

    // The reset cron has not run yet, but the period has elapsed.
    FeatureUsage::where('subscription_id', $sub->id)->update(['period_end' => now()->subDay()]);

    // The next consume triggers an inline reset before incrementing.
    expect(Tashil::usage()->increment($sub, 'api', 10))->toBeTrue();

    // Counter reset to 0 then advanced by 10.
    $usage = FeatureUsage::where('subscription_id', $sub->id)->first();
    expect((float) $usage->usage)->toBe(10.0);

    // The inline reset is captured for audit/replay (this was the H4 gap).
    expect(UsageLog::where('subscription_id', $sub->id)->where('operation', UsageOperation::Reset->value)->count())->toBe(1);
    expect(subscriptionEventCount($sub->id, 'usage.reset'))->toBe(1);
});

it('H3: subscribing a subscriber that already has a live subscription throws', function () {
    subscribeActive($this->user, $this->plan);

    $other = Package::create([
        'name'             => 'Other',
        'slug'             => 'other',
        'price'            => 50.00,
        'billing_period'   => Period::Month,
        'billing_interval' => 1,
    ]);

    expect(fn () => Tashil::subscription()->subscribe($this->user, $other))
        ->toThrow(SubscriptionException::class, 'already has a live subscription');
});

it('H3: a fresh subscribe is allowed once the previous subscription is terminal', function () {
    $sub = subscribeActive($this->user, $this->plan);
    Tashil::subscription()->cancel($sub, immediate: true); // → Cancelled (terminal)

    $again = Tashil::subscription()->subscribe($this->user, $this->plan);
    expect($again)->not->toBeNull();
    expect($again->id)->not->toBe($sub->id);
});
