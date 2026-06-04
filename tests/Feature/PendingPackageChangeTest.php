<?php

use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Events\PendingChangeApplied;
use Foysal50x\Tashil\Events\PendingChangeScheduled;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');
    Carbon::setTestNow('2026-03-15 00:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('scheduleDowngrade records the pending change anchored to current_period_end and dispatches PendingChangeScheduled', function () {
    Event::fake([PendingChangeScheduled::class]);

    $target = Package::factory()->create();
    $sub = Subscription::factory()->create([
        'status'             => SubscriptionStatus::Active,
        'current_period_end' => Carbon::parse('2026-04-01'),
        'ends_at'            => Carbon::parse('2026-04-01'),
        'pending_package_id' => null,
        'pending_change_at'  => null,
    ]);

    $result = Tashil::subscription()->scheduleDowngrade($sub, $target);

    expect($result->pending_package_id)->toBe($target->id);
    expect($result->pending_change_at->toDateString())->toBe('2026-04-01');
    expect($result->hasPendingChange())->toBeTrue();
    expect(subscriptionEventCount($sub->id, 'subscription.pending_change_scheduled'))->toBe(1);
    Event::assertDispatched(PendingChangeScheduled::class, fn ($e) => $e->targetPackage->id === $target->id);
});

it('scheduleDowngrade falls back to ends_at when current_period_end is null', function () {
    $target = Package::factory()->create();
    $sub = Subscription::factory()->create([
        'status'             => SubscriptionStatus::Active,
        'current_period_end' => null,
        'ends_at'            => Carbon::parse('2026-05-20'),
    ]);

    $result = Tashil::subscription()->scheduleDowngrade($sub, $target);

    expect($result->pending_change_at->toDateString())->toBe('2026-05-20');
});

it('cancelPendingChange clears the pending fields and appends an event', function () {
    $target = Package::factory()->create();
    $sub = Subscription::factory()->create([
        'status'             => SubscriptionStatus::Active,
        'pending_package_id' => $target->id,
        'pending_change_at'  => Carbon::parse('2026-04-01'),
    ]);

    $result = Tashil::subscription()->cancelPendingChange($sub);

    expect($result->pending_package_id)->toBeNull();
    expect($result->pending_change_at)->toBeNull();
    expect($result->hasPendingChange())->toBeFalse();
    expect(subscriptionEventCount($sub->id, 'subscription.pending_change_cancelled'))->toBe(1);
});

it('is a no-op to cancel a pending change when none is scheduled', function () {
    $sub = Subscription::factory()->create([
        'status'             => SubscriptionStatus::Active,
        'pending_package_id' => null,
        'pending_change_at'  => null,
    ]);

    $result = Tashil::subscription()->cancelPendingChange($sub);

    expect($result->hasPendingChange())->toBeFalse();
    expect(subscriptionEventCount($sub->id, 'subscription.pending_change_cancelled'))->toBe(0);
});

it('applyPendingChange switches the subscriber onto the target package and dispatches PendingChangeApplied', function () {
    Event::fake([PendingChangeApplied::class]);

    $from = Package::factory()->create(['billing_period' => Period::Month, 'billing_interval' => 1]);
    $to = Package::factory()->create(['billing_period' => Period::Month, 'billing_interval' => 1]);

    $user = createUser();
    $sub = subscribeActive($user, $from);
    Tashil::subscription()->scheduleDowngrade($sub, $to);
    $sub->refresh();

    $newSub = Tashil::subscription()->applyPendingChange($sub);

    // Old subscription cancelled, new subscription active on the target plan.
    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Cancelled);
    expect($newSub->id)->not->toBe($sub->id);
    expect($newSub->package_id)->toBe($to->id);
    expect($newSub->status)->toBe(SubscriptionStatus::Active);
    expect(subscriptionEventCount($sub->id, 'subscription.switched'))->toBe(1);
    Event::assertDispatched(PendingChangeApplied::class);
});

it('is a no-op to applyPendingChange when nothing is scheduled', function () {
    Event::fake([PendingChangeApplied::class]);

    $sub = Subscription::factory()->create([
        'status'             => SubscriptionStatus::Active,
        'pending_package_id' => null,
        'pending_change_at'  => null,
    ]);

    $result = Tashil::subscription()->applyPendingChange($sub);

    expect($result->id)->toBe($sub->id);
    expect($result->status)->toBe(SubscriptionStatus::Active);
    Event::assertNotDispatched(PendingChangeApplied::class);
});

it('is a no-op to applyPendingChange when the target package was removed', function () {
    Event::fake([PendingChangeApplied::class]);

    $from = Package::factory()->create();
    $to = Package::factory()->create();

    $user = createUser();
    $sub = subscribeActive($user, $from);
    Tashil::subscription()->scheduleDowngrade($sub, $to);
    $sub->refresh();

    // The pending row still references the package id, but the package is gone.
    $to->delete();

    $result = Tashil::subscription()->applyPendingChange($sub);

    expect($result->id)->toBe($sub->id);
    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Active);
    Event::assertNotDispatched(PendingChangeApplied::class);
});

it('tashil:apply-pending-changes applies only changes whose effective time has passed', function () {
    $from = Package::factory()->create();
    $to = Package::factory()->create();

    $user = createUser();
    $sub = subscribeActive($user, $from);
    Tashil::subscription()->scheduleDowngrade($sub, $to);

    // Force the scheduled change to be due (period has elapsed).
    $sub->update(['pending_change_at' => now()->subDay()]);

    $this->artisan('tashil:apply-pending-changes')->assertSuccessful();

    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Cancelled);
    expect($user->subscriptions()
        ->where('package_id', $to->id)
        ->where('status', SubscriptionStatus::Active)
        ->exists())->toBeTrue();
});

it('tashil:apply-pending-changes leaves not-yet-due changes untouched', function () {
    $from = Package::factory()->create();
    $to = Package::factory()->create();

    $user = createUser();
    $sub = subscribeActive($user, $from);
    Tashil::subscription()->scheduleDowngrade($sub, $to);

    // Effective date in the future.
    $sub->update(['pending_change_at' => now()->addWeek()]);

    $this->artisan('tashil:apply-pending-changes')->assertSuccessful();

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Active);
    expect($sub->hasPendingChange())->toBeTrue();
});
