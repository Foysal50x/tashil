<?php

declare(strict_types=1);

use Foysal50x\Tashil\Contracts\ShouldBeUnique;
use Foysal50x\Tashil\Exceptions\UniqueIdGenerationException;
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
    $id = app(TransactionIdGenerator::class, ['gateway' => 'manual'])->generate();

    expect($id)->toMatch('/^TXN-\d{6}-\d{6}[0-9A-Z]{2}$/');
});

it('TransactionIdGenerator honors a custom prefix and format from config', function () {
    config([
        'tashil.transaction.prefix' => 'PAY',
        'tashil.transaction.format' => '#-NNNN',
    ]);

    $id = app(TransactionIdGenerator::class, ['gateway' => 'manual'])->generate();

    expect($id)->toMatch('/^PAY-\d{4}$/');
});

it('TransactionIdGenerator opts into uniqueness and reflects the taken ids', function () {
    $generator = app(TransactionIdGenerator::class, ['gateway' => 'manual']);

    expect($generator)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($generator->isUnique('TXN-FREE'))->toBeTrue();

    Transaction::create([
        'invoice_id'     => $this->invoice->id,
        'gateway'        => 'manual',
        'transaction_id' => 'TXN-TAKEN',
        'amount'         => 5.00,
        'currency'       => 'USD',
    ]);

    expect($generator->isUnique('TXN-TAKEN'))->toBeFalse();
});

it('scopes uniqueness to its gateway — the same id under a different gateway is free', function () {
    // A Stripe charge already uses this id.
    Transaction::create([
        'invoice_id'     => $this->invoice->id,
        'gateway'        => 'stripe',
        'transaction_id' => 'SHARED-ID',
        'amount'         => 5.00,
        'currency'       => 'USD',
    ]);

    // Generating for the manual gateway: a different gateway, so it's free.
    expect(app(TransactionIdGenerator::class, ['gateway' => 'manual'])->isUnique('SHARED-ID'))->toBeTrue();

    // Generating for the same (stripe) gateway: the composite clashes.
    expect(app(TransactionIdGenerator::class, ['gateway' => 'stripe'])->isUnique('SHARED-ID'))->toBeFalse();

    // And the composite (manual, SHARED-ID) inserts cleanly alongside the stripe row.
    $manual = Transaction::create([
        'invoice_id'     => $this->invoice->id,
        'gateway'        => 'manual',
        'transaction_id' => 'SHARED-ID',
        'amount'         => 5.00,
        'currency'       => 'USD',
    ]);
    expect($manual->transaction_id)->toBe('SHARED-ID');
});

it('enforces uniqueness within the same gateway — regenerates, and exhausts when that gateway has no free id', function () {
    // A single-value format: build() can only ever render "X".
    config([
        'tashil.transaction.prefix' => 'X',
        'tashil.transaction.format' => '#',
    ]);

    // Occupy the only possible id under the manual gateway.
    Transaction::create([
        'invoice_id'     => $this->invoice->id,
        'gateway'        => 'manual',
        'transaction_id' => 'X',
        'amount'         => 5.00,
        'currency'       => 'USD',
    ]);

    // The manual generator keeps re-rendering "X", never finds a free id within
    // its gateway, and gives up — proving uniqueness is enforced per gateway.
    expect(fn () => app(TransactionIdGenerator::class, ['gateway' => 'manual'])->generate())
        ->toThrow(UniqueIdGenerationException::class);

    // The very same value is free under a different gateway, so generation there
    // succeeds — the enforcement is scoped to the gateway, not global.
    expect(app(TransactionIdGenerator::class, ['gateway' => 'stripe'])->generate())->toBe('X');
});
