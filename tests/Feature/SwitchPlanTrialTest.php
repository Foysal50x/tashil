<?php

declare(strict_types=1);

use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Package;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');
    Carbon::setTestNow('2026-03-15 00:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('switching mid-trial to another trial plan grants the new plan its full trial (not the remaining days)', function () {
    $from = Package::factory()->create(['billing_period' => Period::Month, 'trial_days' => 14]);
    $to = Package::factory()->create(['billing_period' => Period::Month, 'trial_days' => 30]);

    $user = createUser();
    Tashil::subscription()->subscribe($user, $from, withTrial: true);

    $newSub = $user->switchPlan($to);

    expect($newSub->status)->toBe(SubscriptionStatus::OnTrial);
    // Full 30-day trial from "now", not the 14-day remainder of the old trial.
    expect($newSub->trial_ends_at->toDateString())->toBe(now()->addDays(30)->toDateString());
});

it('switching mid-trial to a plan without a trial starts the new plan active', function () {
    $from = Package::factory()->create(['billing_period' => Period::Month, 'trial_days' => 14]);
    $to = Package::factory()->create(['billing_period' => Period::Month, 'trial_days' => 0]);

    $user = createUser();
    Tashil::subscription()->subscribe($user, $from, withTrial: true);

    $newSub = $user->switchPlan($to);

    expect($newSub->status)->toBe(SubscriptionStatus::Active);
    expect($newSub->trial_ends_at)->toBeNull();
});

it('switching from an active (non-trial) subscription does not grant a trial on the new plan', function () {
    $from = Package::factory()->create(['billing_period' => Period::Month, 'trial_days' => 0]);
    $to = Package::factory()->create(['billing_period' => Period::Month, 'trial_days' => 30]);

    $user = createUser();
    subscribeActive($user, $from);

    $newSub = $user->switchPlan($to);

    expect($newSub->status)->toBe(SubscriptionStatus::Active);
    expect($newSub->trial_ends_at)->toBeNull();
});
