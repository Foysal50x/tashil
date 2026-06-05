<?php

declare(strict_types=1);

use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Events\InvoicePaid;
use Foysal50x\Tashil\Events\SubscriptionRenewed;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Carbon::setTestNow('2026-03-15 00:00:00');

    $this->package = Package::factory()->create([
        'billing_period'   => Period::Month,
        'billing_interval' => 1,
    ]);

    $this->sub = Subscription::factory()->create([
        'package_id'           => $this->package->id,
        'status'               => SubscriptionStatus::Active,
        'current_period_start' => Carbon::parse('2026-03-01'),
        'current_period_end'   => Carbon::parse('2026-04-01'),
        'ends_at'              => Carbon::parse('2026-04-01'),
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('marking an invoice paid advances the period and dispatches SubscriptionRenewed + InvoicePaid', function () {
    Event::fake([SubscriptionRenewed::class, InvoicePaid::class]);

    $invoice = Invoice::factory()->create([
        'subscription_id' => $this->sub->id,
        'status'          => InvoiceStatus::Pending,
    ]);

    $invoice->markAsPaid();

    // Period advances anchored to the previous period_end (2026-04-01 + 1 month).
    $this->sub->refresh();
    expect($this->sub->current_period_end->toDateString())->toBe('2026-05-01');
    expect($this->sub->current_period_start->toDateString())->toBe('2026-04-01');
    expect(subscriptionEventCount($this->sub->id, 'subscription.renewed'))->toBe(1);

    Event::assertDispatched(SubscriptionRenewed::class);
    Event::assertDispatched(InvoicePaid::class);
});

it('marking an already-paid invoice paid again does not advance the period a second time', function () {
    $invoice = Invoice::factory()->create([
        'subscription_id' => $this->sub->id,
        'status'          => InvoiceStatus::Pending,
    ]);

    $invoice->markAsPaid();
    $invoice->markAsPaid();

    $this->sub->refresh();
    expect($this->sub->current_period_end->toDateString())->toBe('2026-05-01');
    expect(subscriptionEventCount($this->sub->id, 'subscription.renewed'))->toBe(1);
});
