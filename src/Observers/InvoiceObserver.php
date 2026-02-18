<?php

namespace Foysal50x\Tashil\Observers;

use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Services\Generators\InvoiceNumberGenerator;

class InvoiceObserver
{
    /**
     * Handle the Invoice "creating" event.
     */
    public function creating(Invoice $invoice): void
    {
        if (empty($invoice->invoice_number)) {
            $generatorClass = config('tashil.invoice.generator', InvoiceNumberGenerator::class);
            $generator = app($generatorClass);
            
            $invoice->invoice_number = $generator->generate();
        }
    }
}
