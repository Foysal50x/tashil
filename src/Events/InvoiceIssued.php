<?php

namespace Foysal50x\Tashil\Events;

use Foysal50x\Tashil\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InvoiceIssued
{
    use Dispatchable, SerializesModels;

    public function __construct(public Invoice $invoice) {}
}
