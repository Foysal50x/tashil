<?php

use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Events\TrialEnding;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Carbon::setTestNow('2026-03-15 00:00:00');
    config(['tashil.trial.warn_days' => 3]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('notifies trials ending within the warn window and dispatches TrialEnding', function () {
    Event::fake([TrialEnding::class]);

    $sub = Subscription::factory()->create([
        'status'             => SubscriptionStatus::OnTrial,
        'trial_started_at'   => now()->subDays(11),
        'trial_ends_at'      => now()->addDays(2),
        'trial_converted_at' => null,
    ]);

    $this->artisan('tashil:mark-trials-ending')->assertSuccessful();

    expect(subscriptionEventCount($sub->id, 'trial.ending'))->toBe(1);
    Event::assertDispatched(TrialEnding::class);
});

it('is idempotent within the same day — running twice appends only one trial.ending event', function () {
    $sub = Subscription::factory()->create([
        'status'             => SubscriptionStatus::OnTrial,
        'trial_started_at'   => now()->subDays(11),
        'trial_ends_at'      => now()->addDays(2),
        'trial_converted_at' => null,
    ]);

    $this->artisan('tashil:mark-trials-ending')->assertSuccessful();
    $this->artisan('tashil:mark-trials-ending')->assertSuccessful();

    expect(subscriptionEventCount($sub->id, 'trial.ending'))->toBe(1);
});

it('does not notify trials whose end date is outside the warn window', function () {
    Event::fake([TrialEnding::class]);

    $sub = Subscription::factory()->create([
        'status'             => SubscriptionStatus::OnTrial,
        'trial_started_at'   => now()->subDays(4),
        'trial_ends_at'      => now()->addDays(10),
        'trial_converted_at' => null,
    ]);

    $this->artisan('tashil:mark-trials-ending')->assertSuccessful();

    expect(subscriptionEventCount($sub->id, 'trial.ending'))->toBe(0);
    Event::assertNotDispatched(TrialEnding::class);
});

it('does not notify trials that have already converted', function () {
    Event::fake([TrialEnding::class]);

    $sub = Subscription::factory()->create([
        'status'             => SubscriptionStatus::OnTrial,
        'trial_started_at'   => now()->subDays(11),
        'trial_ends_at'      => now()->addDays(2),
        'trial_converted_at' => now()->subDay(),
    ]);

    $this->artisan('tashil:mark-trials-ending')->assertSuccessful();

    expect(subscriptionEventCount($sub->id, 'trial.ending'))->toBe(0);
    Event::assertNotDispatched(TrialEnding::class);
});

it('honors the --date option when selecting trials to notify', function () {
    Event::fake([TrialEnding::class]);

    // Trial ends 2026-03-20. From "now" (03-15) it is outside the 3-day window,
    // but from --date=2026-03-18 it falls inside it.
    $sub = Subscription::factory()->create([
        'status'             => SubscriptionStatus::OnTrial,
        'trial_started_at'   => now()->subDays(5),
        'trial_ends_at'      => Carbon::parse('2026-03-20'),
        'trial_converted_at' => null,
    ]);

    $this->artisan('tashil:mark-trials-ending', ['--date' => '2026-03-18 00:00:00'])->assertSuccessful();

    expect(subscriptionEventCount($sub->id, 'trial.ending'))->toBe(1);
});
