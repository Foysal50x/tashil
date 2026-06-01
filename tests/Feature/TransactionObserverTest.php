<?php

use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Transaction;
use Foysal50x\Tashil\Services\Generators\TransactionIdGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

beforeEach(function () {
    Carbon::setTestNow('2026-03-15 12:00:00');
    $this->invoice = Invoice::factory()->create();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('stamps a generated TXN id when transaction_id is left blank', function () {
    $txn = Transaction::create([
        'invoice_id' => $this->invoice->id,
        'amount'     => 10.00,
        'currency'   => 'USD',
    ]);

    expect($txn->transaction_id)->not->toBeNull();
    expect($txn->transaction_id)->toMatch('/^TXN-\d{6}-\d{6}[0-9A-Z]{2}$/');
});

it('passes a gateway-supplied transaction_id through unchanged', function () {
    $txn = Transaction::create([
        'invoice_id'     => $this->invoice->id,
        'gateway'        => 'stripe',
        'transaction_id' => 'ch_abc123',
        'amount'         => 10.00,
        'currency'       => 'USD',
    ]);

    expect($txn->transaction_id)->toBe('ch_abc123');
});

it('rejects a duplicate (gateway, transaction_id) pair via the unique constraint', function () {
    Transaction::create([
        'invoice_id'     => $this->invoice->id,
        'gateway'        => 'stripe',
        'transaction_id' => 'ch_dup',
        'amount'         => 10.00,
        'currency'       => 'USD',
    ]);

    expect(fn () => Transaction::create([
        'invoice_id'     => $this->invoice->id,
        'gateway'        => 'stripe',
        'transaction_id' => 'ch_dup',
        'amount'         => 20.00,
        'currency'       => 'USD',
    ]))->toThrow(QueryException::class);
});

it('TransactionIdGenerator produces an id matching the configured default format', function () {
    $id = app(TransactionIdGenerator::class)->generate();

    expect($id)->toMatch('/^TXN-\d{6}-\d{6}[0-9A-Z]{2}$/');
});

it('TransactionIdGenerator honors a custom prefix and format from config', function () {
    config([
        'tashil.transaction.prefix' => 'PAY',
        'tashil.transaction.format' => '#-NNNN',
    ]);

    $id = app(TransactionIdGenerator::class)->generate();

    expect($id)->toMatch('/^PAY-\d{4}$/');
});
