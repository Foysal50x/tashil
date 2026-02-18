<?php

use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $this->package = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 29.99,
        'currency'         => 'EUR',
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    $user = createUser();
    $this->subscription = app('tashil')->subscription()->subscribe($user, $this->package);
});

it('generates an invoice for a subscription', function () {
    $invoice = app('tashil')->billing()->generateInvoice($this->subscription);

    expect($invoice)->toBeInstanceOf(Invoice::class);
    expect($invoice->exists)->toBeTrue();
    expect($invoice->subscription_id)->toBe($this->subscription->id);
    expect($invoice->amount)->toBe('29.99');
    expect($invoice->currency)->toBe('EUR');
    expect($invoice->status)->toBe(InvoiceStatus::Pending);
    expect($invoice->invoice_number)->toStartWith('INV-');
    expect($invoice->issued_at)->not->toBeNull();
    expect($invoice->due_date)->not->toBeNull();
});

it('generates invoice with custom amount', function () {
    $invoice = app('tashil')->billing()->generateInvoice($this->subscription, amount: 49.99);

    expect($invoice->amount)->toBe('49.99');
});

it('generates unique invoice numbers', function () {
    $inv1 = app('tashil')->billing()->generateInvoice($this->subscription);
    $inv2 = app('tashil')->billing()->generateInvoice($this->subscription);

    expect($inv1->invoice_number)->not->toBe($inv2->invoice_number);
});
