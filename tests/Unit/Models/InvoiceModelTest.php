<?php

use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\Transaction;

beforeEach(function () {
    $package = Package::create(['name' => 'Pro', 'slug' => 'pro', 'price' => 29.99]);
    $this->subscription = Subscription::create([
        'subscriber_type' => 'App\\Models\\User',
        'subscriber_id'   => 1,
        'package_id'      => $package->id,
        'status'          => 'active',
        'starts_at'       => now(),
    ]);
    $this->invoice = Invoice::create([
        'subscription_id' => $this->subscription->id,
        'invoice_number'  => 'INV-TEST-001',
        'amount'          => 29.99,
        'currency'        => 'USD',
        'status'          => 'pending',
        'issued_at'       => now(),
        'due_date'        => now()->addDays(7),
    ]);
});

it('creates an invoice with correct attributes', function () {
    expect($this->invoice->invoice_number)->toBe('INV-TEST-001');
    expect($this->invoice->amount)->toBe('29.99');
    expect($this->invoice->currency)->toBe('USD');
    expect($this->invoice->status)->toBe(InvoiceStatus::Pending);
});

it('casts status to InvoiceStatus enum', function () {
    expect($this->invoice->status)->toBeInstanceOf(InvoiceStatus::class);
});

it('has subscription relationship', function () {
    expect($this->invoice->subscription->id)->toBe($this->subscription->id);
});

it('has transactions relationship', function () {
    Transaction::create([
        'invoice_id' => $this->invoice->id,
        'amount'     => 29.99,
        'currency'   => 'USD',
        'gateway'    => 'stripe',
        'status'     => 'success',
    ]);

    expect($this->invoice->transactions)->toHaveCount(1);
});

it('checks isPaid correctly', function () {
    expect($this->invoice->isPaid())->toBeFalse();

    $this->invoice->update(['status' => 'paid', 'paid_at' => now()]);
    expect($this->invoice->refresh()->isPaid())->toBeTrue();
});

it('marks as paid', function () {
    $this->invoice->markAsPaid();

    expect($this->invoice->status)->toBe(InvoiceStatus::Paid);
    expect($this->invoice->paid_at)->not->toBeNull();
});

it('marks as void', function () {
    $this->invoice->markAsVoid();

    expect($this->invoice->status)->toBe(InvoiceStatus::Void);
});

it('scopes to paid invoices', function () {
    $this->invoice->markAsPaid();
    Invoice::create([
        'subscription_id' => $this->subscription->id,
        'invoice_number'  => 'INV-TEST-002',
        'amount'          => 9.99,
        'status'          => 'pending',
        'issued_at'       => now(),
    ]);

    expect(Invoice::paid()->count())->toBe(1);
    expect(Invoice::pending()->count())->toBe(1);
});

it('uses soft deletes', function () {
    $this->invoice->delete();

    expect(Invoice::count())->toBe(0);
    expect(Invoice::withTrashed()->count())->toBe(1);
});
