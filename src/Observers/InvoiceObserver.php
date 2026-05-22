<?php

namespace Foysal50x\Tashil\Observers;

use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Events\InvoiceIssued;
use Foysal50x\Tashil\Events\InvoicePaid;
use Foysal50x\Tashil\Events\InvoiceVoided;
use Foysal50x\Tashil\Events\SubscriptionRenewed;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Services\Generators\InvoiceNumberGenerator;
use Foysal50x\Tashil\Services\SubscriptionService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class InvoiceObserver
{
    public function creating(Invoice $invoice): void
    {
        if (empty($invoice->invoice_number)) {
            $generatorClass = Config::get('tashil.invoice.generator', InvoiceNumberGenerator::class);
            $generator = app($generatorClass);

            $invoice->invoice_number = $generator->generate();
        }
    }

    public function created(Invoice $invoice): void
    {
        $this->dispatchAfterCommit(fn () => InvoiceIssued::dispatch($invoice));
    }

    public function updated(Invoice $invoice): void
    {
        if (! $invoice->wasChanged('status')) {
            return;
        }

        $newStatus = $invoice->status;
        $oldStatus = $invoice->getOriginal('status');

        if ($newStatus === InvoiceStatus::Paid && $oldStatus !== InvoiceStatus::Paid) {
            $this->onPaid($invoice);

            return;
        }

        if ($newStatus === InvoiceStatus::Void && $oldStatus !== InvoiceStatus::Void) {
            $this->dispatchAfterCommit(fn () => InvoiceVoided::dispatch($invoice));
        }
    }

    protected function onPaid(Invoice $invoice): void
    {
        $subscription = $invoice->subscription()->first();

        if ($subscription) {
            $subscription = app(SubscriptionService::class)->advancePeriod($subscription);
            $this->dispatchAfterCommit(fn () => SubscriptionRenewed::dispatch($subscription, $invoice));
        }

        $this->dispatchAfterCommit(fn () => InvoicePaid::dispatch($invoice));
    }

    protected function dispatchAfterCommit(\Closure $dispatcher): void
    {
        if (Config::get('tashil.events.async', true)) {
            DB::afterCommit($dispatcher);

            return;
        }

        $dispatcher();
    }
}
