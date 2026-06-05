<?php

declare(strict_types=1);

use Foysal50x\Tashil\Enums\InvoiceKind;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $this->paid = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 30.00,
        'currency'         => 'USD',
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    $this->billing = app('tashil')->billing();
});

it('latestInvoice returns the most recent invoice, optionally filtered by kind', function () {
    $sub = app('tashil')->subscription()->subscribe(createUser(), $this->paid);
    $initial = Invoice::where('subscription_id', $sub->id)->firstOrFail();   // kind = initial

    $renewal = $this->billing->generateInvoice($sub, kind: InvoiceKind::Renewal);

    expect($this->billing->latestInvoice($sub)->id)->toBe($renewal->id)
        ->and($this->billing->latestInvoice($sub, InvoiceKind::Initial)->id)->toBe($initial->id)
        ->and($this->billing->latestInvoice($sub, InvoiceKind::Renewal)->id)->toBe($renewal->id);
});

it('latestInvoice returns null for a subscription with no invoices', function () {
    $free = Package::create([
        'name'             => 'Free',
        'slug'             => 'free',
        'price'            => 0.00,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    $sub = app('tashil')->subscription()->subscribe(createUser(), $free);

    expect($this->billing->latestInvoice($sub))->toBeNull();
});

it('pendingInvoice returns the outstanding invoice and ignores paid ones', function () {
    $sub = app('tashil')->subscription()->subscribe(createUser(), $this->paid);
    $initial = Invoice::where('subscription_id', $sub->id)->firstOrFail();

    expect($this->billing->pendingInvoice($sub)->id)->toBe($initial->id);

    // Pay it → nothing outstanding.
    $this->billing->recordPayment($initial, gateway: 'stripe', transactionId: 'ch_pending');
    expect($this->billing->pendingInvoice($sub->refresh()))->toBeNull();

    // A fresh renewal becomes the outstanding invoice.
    $renewal = $this->billing->generateInvoice($sub, kind: InvoiceKind::Renewal);
    expect($this->billing->pendingInvoice($sub)->id)->toBe($renewal->id);
});

it('overdueInvoice returns only a pending invoice past its due date', function () {
    $sub = app('tashil')->subscription()->subscribe(createUser(), $this->paid);

    // The initial invoice is due in the future — not overdue yet.
    expect($this->billing->overdueInvoice($sub))->toBeNull();

    // A renewal whose due date is in the past is overdue.
    $overdue = $this->billing->generateInvoice($sub, kind: InvoiceKind::Renewal, dueDays: -2);
    expect($this->billing->overdueInvoice($sub)->id)->toBe($overdue->id);

    // Paying it clears the overdue result.
    $this->billing->recordPayment($overdue, gateway: 'stripe', transactionId: 'ch_overdue');
    expect($this->billing->overdueInvoice($sub->refresh()))->toBeNull();
});

it('successfulTransaction returns the settled charge for an invoice, else null', function () {
    $sub = app('tashil')->subscription()->subscribe(createUser(), $this->paid);
    $invoice = Invoice::where('subscription_id', $sub->id)->firstOrFail();

    // No charge recorded yet.
    expect($this->billing->successfulTransaction($invoice))->toBeNull();

    // A failed attempt does not count.
    $this->billing->recordFailedPayment($invoice, gateway: 'stripe', transactionId: 'ch_fail');
    expect($this->billing->successfulTransaction($invoice))->toBeNull();

    // The successful charge is returned.
    $txn = $this->billing->recordPayment($invoice, gateway: 'stripe', transactionId: 'ch_ok');
    expect($this->billing->successfulTransaction($invoice->refresh())->id)->toBe($txn->id);
});
