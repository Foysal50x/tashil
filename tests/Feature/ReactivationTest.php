<?php

declare(strict_types=1);

use Foysal50x\Tashil\Enums\InvoiceKind;
use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Events\SubscriptionReactivated;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');
    Carbon::setTestNow('2026-03-15 00:00:00');

    $this->package = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 30.00,
        'billing_period'   => Period::Month,
        'billing_interval' => 1,
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('reactivates an expired subscription when an invoice is paid and restores access', function () {
    Event::fake([SubscriptionReactivated::class]);

    $user = createUser();
    $sub = subscribeActive($user, $this->package);

    // The period elapses and the subscription lapses to Expired.
    $this->travelTo($sub->current_period_end->copy()->addDays(5));
    Tashil::subscription()->expire($sub);
    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Expired);

    // Host collects payment on a recovery invoice.
    $paidAt = now();
    $invoice = Invoice::factory()->create([
        'subscription_id' => $sub->id,
        'kind'            => InvoiceKind::Renewal,
        'status'          => InvoiceStatus::Pending,
    ]);
    $invoice->markAsPaid();

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Active);
    expect($sub->isValid())->toBeTrue();
    // Fresh period anchored to the recovery payment.
    expect($sub->current_period_start->toDateTimeString())->toBe($paidAt->toDateTimeString());
    expect($sub->current_period_end->toDateTimeString())->toBe($paidAt->copy()->addMonth()->toDateTimeString());

    $user->clearSubscriptionCache();
    expect($user->subscribed())->toBeTrue();

    Event::assertDispatched(SubscriptionReactivated::class, fn ($e) => $e->subscription->id === $sub->id && $e->invoice?->id === $invoice->id);
    expect(subscriptionEventCount($sub->id, 'subscription.reactivated'))->toBe(1);
});

it('clears dunning state (attempts, suspended_at) on reactivation', function () {
    $sub = Subscription::factory()->create([
        'package_id'         => $this->package->id,
        'status'             => SubscriptionStatus::Suspended,
        'current_period_end' => now()->subDays(10),
        'ends_at'            => now()->subDays(10),
        'dunning_attempts'   => 3,
        'last_dunning_at'    => now()->subDay(),
        'suspended_at'       => now()->subDays(2),
    ]);

    $result = Tashil::subscription()->reactivate($sub);

    expect($result->status)->toBe(SubscriptionStatus::Active);
    expect($result->dunning_attempts)->toBe(0);
    expect($result->last_dunning_at)->toBeNull();
    expect($result->suspended_at)->toBeNull();
    expect($result->current_period_end->isFuture())->toBeTrue();
});

it('is a no-op to reactivate an active subscription', function () {
    Event::fake([SubscriptionReactivated::class]);

    $user = createUser();
    $sub = subscribeActive($user, $this->package);
    $periodEnd = $sub->current_period_end->toDateTimeString();

    $result = Tashil::subscription()->reactivate($sub);

    expect($result->status)->toBe(SubscriptionStatus::Active);
    expect($result->current_period_end->toDateTimeString())->toBe($periodEnd);
    Event::assertNotDispatched(SubscriptionReactivated::class);
    expect(subscriptionEventCount($sub->id, 'subscription.reactivated'))->toBe(0);
});

it('does not advance or reactivate a cancelled subscription when an invoice is paid (M1 guard)', function () {
    $user = createUser();
    $sub = subscribeActive($user, $this->package);
    Tashil::subscription()->cancel($sub, immediate: true);
    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Cancelled);
    $endsAt = $sub->ends_at?->toDateTimeString();

    $invoice = Invoice::factory()->create([
        'subscription_id' => $sub->id,
        'kind'            => InvoiceKind::Renewal,
        'status'          => InvoiceStatus::Pending,
    ]);
    $invoice->markAsPaid();

    $sub->refresh();
    // Cancelled is terminal here — paying a stray invoice neither advances
    // the period nor silently reactivates it.
    expect($sub->status)->toBe(SubscriptionStatus::Cancelled);
    expect($sub->ends_at?->toDateTimeString())->toBe($endsAt);
    expect(subscriptionEventCount($sub->id, 'subscription.renewed'))->toBe(0);
    expect(subscriptionEventCount($sub->id, 'subscription.reactivated'))->toBe(0);
});
