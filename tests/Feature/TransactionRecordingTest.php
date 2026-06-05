<?php

declare(strict_types=1);

use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Enums\TransactionStatus;
use Foysal50x\Tashil\Events\PaymentFailed;
use Foysal50x\Tashil\Events\PaymentRecorded;
use Foysal50x\Tashil\Events\PaymentRefunded;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Transaction;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    // Priced plan → subscribes Pending with an initial invoice (the realistic
    // record-payment subject).
    $this->paid = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 30.00,
        'currency'         => 'USD',
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);
});

/**
 * Subscribe a fresh user and return its pending initial invoice.
 */
function pendingInitialInvoice(Package $plan): Invoice
{
    $sub = app('tashil')->subscription()->subscribe(createUser(), $plan);

    return Invoice::where('subscription_id', $sub->id)->latest('id')->firstOrFail();
}

it('records a successful transaction and settles + activates the subscription', function () {
    $invoice = pendingInitialInvoice($this->paid);
    $sub = $invoice->subscription;

    expect($sub->status)->toBe(SubscriptionStatus::Pending);

    $txn = app('tashil')->billing()->recordPayment(
        invoice: $invoice,
        gateway: 'stripe',
        transactionId: 'ch_settle',
        gatewayResponse: ['captured' => true],
    );

    expect($txn)->toBeInstanceOf(Transaction::class)
        ->and($txn->status)->toBe(TransactionStatus::Success)
        ->and($txn->gateway)->toBe('stripe')
        ->and($txn->transaction_id)->toBe('ch_settle')
        ->and((float) $txn->amount)->toBe(30.00)
        ->and($txn->currency)->toBe('USD')
        ->and($txn->invoice_id)->toBe($invoice->id)
        ->and($txn->gateway_response)->toBe(['captured' => true]);

    expect($invoice->refresh()->isPaid())->toBeTrue();
    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Active);
    expect(Transaction::where('invoice_id', $invoice->id)->count())->toBe(1);
});

it('is idempotent on (gateway, transaction_id) — a replayed webhook returns the same row and does not double-record', function () {
    $invoice = pendingInitialInvoice($this->paid);
    $billing = app('tashil')->billing();

    $first = $billing->recordPayment($invoice, gateway: 'stripe', transactionId: 'ch_replay');
    $second = $billing->recordPayment($invoice->refresh(), gateway: 'stripe', transactionId: 'ch_replay');

    expect($second->id)->toBe($first->id);
    expect(Transaction::where('invoice_id', $invoice->id)->count())->toBe(1);
    expect($invoice->refresh()->isPaid())->toBeTrue();
});

it('stamps a generated TXN id for a manual (gateway-less) payment', function () {
    $invoice = pendingInitialInvoice($this->paid);

    $txn = app('tashil')->billing()->recordPayment($invoice);

    expect($txn->gateway)->toBe('manual')
        ->and($txn->transaction_id)->toMatch('/^TXN-/')
        ->and($invoice->refresh()->isPaid())->toBeTrue();
});

it('defaults the amount to the full invoice amount', function () {
    $invoice = pendingInitialInvoice($this->paid);

    $txn = app('tashil')->billing()->recordPayment($invoice);

    expect((float) $txn->amount)->toBe(30.00);
});

it('rejects a non-positive payment amount', function () {
    $invoice = pendingInitialInvoice($this->paid);

    expect(fn () => app('tashil')->billing()->recordPayment($invoice, amount: 0.0))
        ->toThrow(InvalidArgumentException::class);
});

it('dispatches PaymentRecorded carrying the transaction and invoice', function () {
    Event::fake([PaymentRecorded::class]);
    $invoice = pendingInitialInvoice($this->paid);

    $txn = app('tashil')->billing()->recordPayment($invoice, gateway: 'stripe', transactionId: 'ch_evt');

    Event::assertDispatched(
        PaymentRecorded::class,
        fn ($e) => $e->transaction->id === $txn->id && $e->invoice->id === $invoice->id,
    );
});

it('records a failed payment as a failed transaction and leaves the invoice pending for dunning', function () {
    Event::fake([PaymentFailed::class]);
    $invoice = pendingInitialInvoice($this->paid);
    $sub = $invoice->subscription;

    $txn = app('tashil')->billing()->recordFailedPayment(
        invoice: $invoice,
        gateway: 'stripe',
        transactionId: 'ch_declined',
        gatewayResponse: ['decline_code' => 'insufficient_funds'],
    );

    expect($txn->status)->toBe(TransactionStatus::Failed)
        ->and($txn->gateway_response)->toBe(['decline_code' => 'insufficient_funds']);

    // No state transition — the dunning cycle owns the retry.
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Pending);
    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Pending);

    Event::assertDispatched(PaymentFailed::class, fn ($e) => $e->transaction->id === $txn->id);
});

it('records a full refund — transaction Refunded, invoice Refunded, PaymentRefunded fired', function () {
    Event::fake([PaymentRefunded::class]);
    $invoice = pendingInitialInvoice($this->paid);
    $billing = app('tashil')->billing();

    $txn = $billing->recordPayment($invoice, gateway: 'stripe', transactionId: 'ch_refund_full');

    $refunded = $billing->recordRefund($txn, reason: 'customer request');

    expect($refunded->status)->toBe(TransactionStatus::Refunded)
        ->and((float) $refunded->refunded_amount)->toBe(30.00)
        ->and($refunded->refunded_at)->not->toBeNull()
        ->and($refunded->refund_reason)->toBe('customer request');

    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Refunded);

    Event::assertDispatched(
        PaymentRefunded::class,
        fn ($e) => $e->transaction->id === $txn->id && $e->invoice->id === $invoice->id,
    );
});

it('records a partial refund — transaction stays Success and invoice stays Paid', function () {
    $invoice = pendingInitialInvoice($this->paid);
    $billing = app('tashil')->billing();

    $txn = $billing->recordPayment($invoice, gateway: 'stripe', transactionId: 'ch_refund_partial');

    $refunded = $billing->recordRefund($txn, amount: 10.00);

    expect($refunded->status)->toBe(TransactionStatus::Success)
        ->and((float) $refunded->refunded_amount)->toBe(10.00);
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Paid);
});

it('accumulates partial refunds and flips to Refunded once the full amount is reached', function () {
    $invoice = pendingInitialInvoice($this->paid);
    $billing = app('tashil')->billing();

    $txn = $billing->recordPayment($invoice, gateway: 'stripe', transactionId: 'ch_refund_two');

    $billing->recordRefund($txn, amount: 18.00);
    $final = $billing->recordRefund($txn->refresh(), amount: 12.00);

    expect($final->status)->toBe(TransactionStatus::Refunded)
        ->and((float) $final->refunded_amount)->toBe(30.00);
    expect($invoice->refresh()->status)->toBe(InvoiceStatus::Refunded);
});

it('rejects a refund larger than the refundable balance', function () {
    $invoice = pendingInitialInvoice($this->paid);
    $billing = app('tashil')->billing();

    $txn = $billing->recordPayment($invoice, gateway: 'stripe', transactionId: 'ch_over');

    expect(fn () => $billing->recordRefund($txn, amount: 31.00))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects refunding a non-successful transaction', function () {
    $invoice = pendingInitialInvoice($this->paid);
    $billing = app('tashil')->billing();

    $failed = $billing->recordFailedPayment($invoice, gateway: 'stripe', transactionId: 'ch_failed_refund');

    expect(fn () => $billing->recordRefund($failed))
        ->toThrow(InvalidArgumentException::class);
});
