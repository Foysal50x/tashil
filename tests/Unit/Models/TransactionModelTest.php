<?php

use Foysal50x\Tashil\Enums\TransactionStatus;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\Transaction;

beforeEach(function () {
    $package = Package::create(['name' => 'Pro', 'slug' => 'pro', 'price' => 29.99]);
    $sub = Subscription::create([
        'subscriber_type' => 'App\\Models\\User',
        'subscriber_id'   => 1,
        'package_id'      => $package->id,
        'status'          => 'active',
        'starts_at'       => now(),
    ]);
    $this->invoice = Invoice::create([
        'subscription_id' => $sub->id,
        'invoice_number'  => 'INV-TEST-001',
        'amount'          => 29.99,
        'status'          => 'pending',
        'issued_at'       => now(),
    ]);
    $this->transaction = Transaction::create([
        'invoice_id'             => $this->invoice->id,
        'amount'                 => 29.99,
        'currency'               => 'USD',
        'gateway'                => 'stripe',
        'transaction_id'         => 'txn_123abc',
        'status'                 => 'success',
        'metadata'               => ['card_last4' => '4242'],
    ]);
});

it('creates a transaction with correct attributes', function () {
    expect($this->transaction->amount)->toBe('29.99');
    expect($this->transaction->gateway)->toBe('stripe');
    expect($this->transaction->transaction_id)->toBe('txn_123abc');
    expect($this->transaction->status)->toBe(TransactionStatus::Success);
    expect($this->transaction->metadata)->toBe(['card_last4' => '4242']);
});

it('casts status to TransactionStatus enum', function () {
    expect($this->transaction->status)->toBeInstanceOf(TransactionStatus::class);
});

it('has invoice relationship', function () {
    expect($this->transaction->invoice->id)->toBe($this->invoice->id);
});

it('detects successful transaction', function () {
    expect($this->transaction->isSuccessful())->toBeTrue();

    $failed = Transaction::create([
        'invoice_id' => $this->invoice->id,
        'amount'     => 29.99,
        'currency'   => 'USD',
        'currency'   => 'USD',
        'status'     => 'failed',
    ]);
    expect($failed->isSuccessful())->toBeFalse();
});

it('detects refunded transaction', function () {
    expect($this->transaction->isRefunded())->toBeFalse();

    $this->transaction->update([
        'status'          => 'refunded',
        'refunded_amount' => 29.99,
        'refund_reason'   => 'Customer request',
        'refunded_at'     => now(),
    ]);

    expect($this->transaction->refresh()->isRefunded())->toBeTrue();
    expect($this->transaction->refunded_amount)->toBe('29.99');
    expect($this->transaction->refund_reason)->toBe('Customer request');
    expect($this->transaction->refunded_at)->not->toBeNull();
});
