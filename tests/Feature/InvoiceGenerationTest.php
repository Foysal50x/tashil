<?php

use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Services\BillingService;
use Illuminate\Support\Facades\Config;

it('auto generates invoice number on creation via observer', function () {
    Config::set('tashil.invoice.prefix', 'AUTOGEN');
    Config::set('tashil.invoice.format', '#-NNNN');

    $invoice = Invoice::factory()->create([
        'invoice_number' => null, // Ensure observer triggers
    ]);

    expect($invoice->invoice_number)->not->toBeNull()
        ->and($invoice->invoice_number)->toStartWith('AUTOGEN-')
        ->and($invoice->invoice_number)->toMatch('/^AUTOGEN-[0-9]{4}$/');
});

it('uses billing service to create invoice with generated number', function () {
    Config::set('tashil.invoice.prefix', 'BILLING');
    Config::set('tashil.invoice.format', '#-NNNN');

    $subscription = Subscription::factory()->create();
    $billingService = app(BillingService::class);

    $invoice = $billingService->generateInvoice($subscription);

    expect($invoice->invoice_number)->not->toBeNull()
        ->and($invoice->invoice_number)->toStartWith('BILLING-');
});
