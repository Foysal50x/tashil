<?php

use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Events\SubscriptionActivated;
use Foysal50x\Tashil\Events\SubscriptionPaused;
use Foysal50x\Tashil\Events\SubscriptionUnpaused;
use Foysal50x\Tashil\Events\TrialConverted;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Carbon::setTestNow('2026-03-15 00:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('pauses an active subscription, appends an event, and dispatches SubscriptionPaused', function () {
    Event::fake([SubscriptionPaused::class]);

    $sub = Subscription::factory()->create(['status' => SubscriptionStatus::Active]);

    $result = Tashil::subscription()->pause($sub);

    expect($result->status)->toBe(SubscriptionStatus::Paused);
    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Paused);
    expect(subscriptionEventCount($sub->id, 'subscription.paused'))->toBe(1);
    Event::assertDispatched(SubscriptionPaused::class);
});

it('does not re-pause an already paused subscription (no duplicate event)', function () {
    Event::fake([SubscriptionPaused::class]);

    $sub = Subscription::factory()->create(['status' => SubscriptionStatus::Paused]);

    $result = Tashil::subscription()->pause($sub);

    expect($result->status)->toBe(SubscriptionStatus::Paused);
    expect(subscriptionEventCount($sub->id, 'subscription.paused'))->toBe(0);
    Event::assertNotDispatched(SubscriptionPaused::class);
});

it('unpauses a paused subscription back to active and dispatches SubscriptionUnpaused', function () {
    Event::fake([SubscriptionUnpaused::class]);

    $sub = Subscription::factory()->create(['status' => SubscriptionStatus::Paused]);

    $result = Tashil::subscription()->unpause($sub);

    expect($result->status)->toBe(SubscriptionStatus::Active);
    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Active);
    expect(subscriptionEventCount($sub->id, 'subscription.unpaused'))->toBe(1);
    Event::assertDispatched(SubscriptionUnpaused::class);
});

it('is a no-op to unpause a subscription that is not paused', function () {
    Event::fake([SubscriptionUnpaused::class]);

    $sub = Subscription::factory()->create(['status' => SubscriptionStatus::Active]);

    $result = Tashil::subscription()->unpause($sub);

    expect($result->status)->toBe(SubscriptionStatus::Active);
    expect(subscriptionEventCount($sub->id, 'subscription.unpaused'))->toBe(0);
    Event::assertNotDispatched(SubscriptionUnpaused::class);
});

it('converts a trial to active, stamps trial_converted_at, and dispatches TrialConverted', function () {
    Event::fake([TrialConverted::class]);

    $sub = Subscription::factory()->create([
        'status'             => SubscriptionStatus::OnTrial,
        'trial_started_at'   => now()->subDays(2),
        'trial_ends_at'      => now()->addDays(10),
        'trial_converted_at' => null,
    ]);

    $result = Tashil::subscription()->convertTrial($sub);

    expect($result->status)->toBe(SubscriptionStatus::Active);
    expect($result->trial_converted_at)->not->toBeNull();
    expect(subscriptionEventCount($sub->id, 'trial.converted'))->toBe(1);
    Event::assertDispatched(TrialConverted::class);
});

it('also dispatches SubscriptionActivated when a trial converts (OnTrial → Active)', function () {
    Event::fake([SubscriptionActivated::class]);

    $sub = Subscription::factory()->create([
        'status'             => SubscriptionStatus::OnTrial,
        'trial_started_at'   => now()->subDays(2),
        'trial_ends_at'      => now()->addDays(10),
        'trial_converted_at' => null,
    ]);

    Tashil::subscription()->convertTrial($sub);

    // A converted trial flows through the standard activation event path so
    // activation listeners are not skipped. The invoice is null — the just-
    // issued initial invoice is unpaid, no payment drove this transition.
    Event::assertDispatched(
        SubscriptionActivated::class,
        fn ($e) => $e->subscription->id === $sub->id && $e->invoice === null,
    );
});

it('does not convert a subscription that is not on trial', function () {
    Event::fake([TrialConverted::class]);

    $sub = Subscription::factory()->create([
        'status'        => SubscriptionStatus::Active,
        'trial_ends_at' => null,
    ]);

    $result = Tashil::subscription()->convertTrial($sub);

    expect($result->status)->toBe(SubscriptionStatus::Active);
    expect($result->trial_converted_at)->toBeNull();
    expect(subscriptionEventCount($sub->id, 'trial.converted'))->toBe(0);
    Event::assertNotDispatched(TrialConverted::class);
});

it('is idempotent to expire an already expired subscription (no duplicate event)', function () {
    $sub = Subscription::factory()->create(['status' => SubscriptionStatus::Expired]);

    $result = Tashil::subscription()->expire($sub);

    expect($result->status)->toBe(SubscriptionStatus::Expired);
    expect(subscriptionEventCount($sub->id, 'subscription.expired'))->toBe(0);
});

it('does not resume a subscription that is not pending cancellation', function () {
    $sub = Subscription::factory()->create(['status' => SubscriptionStatus::Active]);

    $result = Tashil::subscription()->resume($sub);

    expect($result->status)->toBe(SubscriptionStatus::Active);
    expect(subscriptionEventCount($sub->id, 'subscription.resumed'))->toBe(0);
});

it('does not resume a pending-cancellation subscription whose grace window already passed', function () {
    $sub = Subscription::factory()->create([
        'status'  => SubscriptionStatus::PendingCancellation,
        'ends_at' => now()->subDay(),
    ]);

    $result = Tashil::subscription()->resume($sub);

    expect($result->status)->toBe(SubscriptionStatus::PendingCancellation);
    expect(subscriptionEventCount($sub->id, 'subscription.resumed'))->toBe(0);
});

it('advancePeriod anchors the new period to the previous period_end, not to now (late cron must not drift)', function () {
    $package = Package::factory()->create([
        'billing_period'   => Period::Month,
        'billing_interval' => 1,
    ]);

    // current_period_end is in the past relative to "now" (a late cron run).
    $sub = Subscription::factory()->create([
        'package_id'           => $package->id,
        'status'               => SubscriptionStatus::Active,
        'current_period_start' => Carbon::parse('2025-12-10'),
        'current_period_end'   => Carbon::parse('2026-01-10'),
        'ends_at'              => Carbon::parse('2026-01-10'),
    ]);

    $result = Tashil::subscription()->advancePeriod($sub);

    // Anchored to the previous period_end (2026-01-10 + 1 month), NOT now (2026-03-15).
    expect($result->current_period_start->toDateString())->toBe('2026-01-10');
    expect($result->current_period_end->toDateString())->toBe('2026-02-10');
    expect($result->current_period_end->isPast())->toBeTrue();
    expect(subscriptionEventCount($sub->id, 'subscription.renewed'))->toBe(1);
});

it('advancePeriod keeps current_period_end null for a lifetime package', function () {
    $package = Package::factory()->create([
        'billing_period'   => Period::Lifetime,
        'billing_interval' => 1,
    ]);

    $sub = Subscription::factory()->create([
        'package_id'         => $package->id,
        'status'             => SubscriptionStatus::Active,
        'current_period_end' => null,
        'ends_at'            => null,
    ]);

    $result = Tashil::subscription()->advancePeriod($sub);

    expect($result->current_period_end)->toBeNull();
    expect($result->ends_at)->toBeNull();
});
