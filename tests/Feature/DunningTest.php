<?php

use Foysal50x\Tashil\Enums\InvoiceKind;
use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Events\InvoiceOverdue;
use Foysal50x\Tashil\Events\SubscriptionPastDue;
use Foysal50x\Tashil\Events\SubscriptionReactivated;
use Foysal50x\Tashil\Events\SubscriptionSuspended;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Carbon::setTestNow('2026-03-01 00:00:00');

    // Default dunning config: retry at 1/3/5 days, suspend after 3 attempts,
    // expire 7 days after suspension.
    config()->set('tashil.dunning', [
        'enabled'                    => true,
        'retry_days'                 => [1, 3, 5],
        'suspend_after_attempts'     => 3,
        'cancel_after_suspend_days'  => 7,
        'keep_access_while_past_due' => true,
    ]);

    $this->package = Package::factory()->create([
        'billing_period'   => Period::Month,
        'billing_interval' => 1,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * Active subscription whose period has elapsed, with one unpaid renewal
 * invoice due on the period boundary.
 */
function lapsing(int $packageId, string $periodEnd = '2026-03-01'): Subscription
{
    $sub = Subscription::factory()->create([
        'package_id'           => $packageId,
        'status'               => SubscriptionStatus::Active,
        'current_period_start' => Carbon::parse($periodEnd)->subMonth(),
        'current_period_end'   => Carbon::parse($periodEnd),
        'ends_at'              => Carbon::parse($periodEnd),
        'auto_renew'           => true,
    ]);

    Invoice::factory()->create([
        'subscription_id' => $sub->id,
        'kind'            => InvoiceKind::Renewal,
        'status'          => InvoiceStatus::Pending,
        'due_date'        => Carbon::parse($periodEnd),
    ]);

    return $sub;
}

it('moves an overdue subscription to past_due, fires events, and retains access by default', function () {
    Event::fake([SubscriptionPastDue::class, InvoiceOverdue::class]);

    $sub = lapsing($this->package->id);

    // One day past due = first retry milestone.
    $this->artisan('tashil:process-dunning', ['--date' => '2026-03-02 00:00:00'])->assertSuccessful();

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::PastDue);
    expect($sub->dunning_attempts)->toBe(1);
    expect($sub->last_dunning_at)->not->toBeNull();
    // Soft dunning: access retained while past_due.
    expect($sub->isValid())->toBeTrue();

    Event::assertDispatched(SubscriptionPastDue::class, fn ($e) => $e->subscription->id === $sub->id && $e->attempt === 1);
    Event::assertDispatched(InvoiceOverdue::class);
    expect(subscriptionEventCount($sub->id, 'subscription.past_due'))->toBe(1);
});

it('increments the attempt count on each new retry milestone', function () {
    $sub = lapsing($this->package->id);

    $this->artisan('tashil:process-dunning', ['--date' => '2026-03-02 00:00:00'])->assertSuccessful();
    expect($sub->refresh()->dunning_attempts)->toBe(1);

    // Re-running on the same day must NOT re-fire / re-increment.
    $this->artisan('tashil:process-dunning', ['--date' => '2026-03-02 12:00:00'])->assertSuccessful();
    expect($sub->refresh()->dunning_attempts)->toBe(1);
    expect(subscriptionEventCount($sub->id, 'subscription.past_due'))->toBe(1);

    // Third day past due = second milestone.
    $this->artisan('tashil:process-dunning', ['--date' => '2026-03-04 00:00:00'])->assertSuccessful();
    expect($sub->refresh()->dunning_attempts)->toBe(2);
    expect(subscriptionEventCount($sub->id, 'subscription.past_due'))->toBe(2);
});

it('suspends the subscription and cuts access once retries are exhausted', function () {
    Event::fake([SubscriptionSuspended::class]);

    $sub = lapsing($this->package->id);

    // Five days past due → 3 milestones → attempt 3 >= suspend_after_attempts.
    // A single catch-up run cascades Active → PastDue → Suspended.
    $this->artisan('tashil:process-dunning', ['--date' => '2026-03-06 00:00:00'])->assertSuccessful();

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Suspended);
    expect($sub->suspended_at)->not->toBeNull();
    // Suspended never has access, even with keep_access_while_past_due on.
    expect($sub->isValid())->toBeFalse();

    Event::assertDispatched(SubscriptionSuspended::class);
    expect(subscriptionEventCount($sub->id, 'subscription.suspended'))->toBe(1);
});

it('expires a suspended subscription after the suspension grace window', function () {
    $sub = Subscription::factory()->create([
        'package_id'         => $this->package->id,
        'status'             => SubscriptionStatus::Suspended,
        'current_period_end' => Carbon::parse('2026-03-01'),
        'ends_at'            => Carbon::parse('2026-03-01'),
        'suspended_at'       => Carbon::parse('2026-03-06'),
    ]);
    Invoice::factory()->create([
        'subscription_id' => $sub->id,
        'kind'            => InvoiceKind::Renewal,
        'status'          => InvoiceStatus::Pending,
        'due_date'        => Carbon::parse('2026-03-01'),
    ]);

    // Before the 7-day suspend grace elapses → still suspended.
    $this->artisan('tashil:process-dunning', ['--date' => '2026-03-12 00:00:00'])->assertSuccessful();
    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Suspended);

    // On/after suspended_at + 7 days → expired.
    $this->artisan('tashil:process-dunning', ['--date' => '2026-03-13 00:00:00'])->assertSuccessful();
    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Expired);
});

it('recovers the subscription when the overdue invoice is paid during dunning', function () {
    Event::fake([SubscriptionReactivated::class]);

    $sub = lapsing($this->package->id);
    $this->artisan('tashil:process-dunning', ['--date' => '2026-03-04 00:00:00'])->assertSuccessful();
    expect($sub->refresh()->status)->toBe(SubscriptionStatus::PastDue);
    expect($sub->dunning_attempts)->toBe(2);

    // Host collects payment.
    $this->travelTo(Carbon::parse('2026-03-04 06:00:00'));
    Invoice::where('subscription_id', $sub->id)->first()->markAsPaid();

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Active);
    expect($sub->dunning_attempts)->toBe(0);
    expect($sub->last_dunning_at)->toBeNull();
    Event::assertDispatched(SubscriptionReactivated::class);
});

it('denies access while past_due when keep_access_while_past_due is false (hard dunning)', function () {
    config()->set('tashil.dunning.keep_access_while_past_due', false);

    $sub = lapsing($this->package->id);
    $this->artisan('tashil:process-dunning', ['--date' => '2026-03-02 00:00:00'])->assertSuccessful();

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::PastDue);
    expect($sub->isValid())->toBeFalse();
    expect(Subscription::valid()->whereKey($sub->id)->exists())->toBeFalse();
});

it('does nothing when dunning is disabled', function () {
    config()->set('tashil.dunning.enabled', false);

    $sub = lapsing($this->package->id);
    $this->artisan('tashil:process-dunning', ['--date' => '2026-03-10 00:00:00'])->assertSuccessful();

    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Active);
});

it('caps extend_grace so an unpaid invoice cannot extend the period forever', function () {
    config()->set('tashil.renewal.on_pending_invoice', 'extend_grace');
    config()->set('tashil.renewal.grace_days', 3);
    config()->set('tashil.renewal.max_grace_extensions', 2);

    $sub = lapsing($this->package->id);

    // First two runs extend the period and bump the counter.
    $this->artisan('tashil:renew-subscriptions', ['--date' => '2026-03-01 00:00:00'])->assertSuccessful();
    expect($sub->refresh()->dunning_attempts)->toBe(1);
    $afterFirst = $sub->current_period_end->copy();

    // Make it due again, run once more.
    $sub->update(['current_period_end' => Carbon::parse('2026-03-01')]);
    $this->artisan('tashil:renew-subscriptions', ['--date' => '2026-03-01 00:00:00'])->assertSuccessful();
    expect($sub->refresh()->dunning_attempts)->toBe(2);

    // Third attempt is capped — no further extension.
    $sub->update(['current_period_end' => Carbon::parse('2026-03-01')]);
    $this->artisan('tashil:renew-subscriptions', ['--date' => '2026-03-01 00:00:00'])->assertSuccessful();
    $sub->refresh();
    expect($sub->dunning_attempts)->toBe(2);
    expect($sub->current_period_end->toDateString())->toBe('2026-03-01');
});
