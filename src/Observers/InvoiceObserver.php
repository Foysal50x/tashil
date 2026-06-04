<?php

namespace Foysal50x\Tashil\Observers;

use Foysal50x\Tashil\Enums\InvoiceKind;
use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
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

    /**
     * Route a paid invoice by its kind and the subscription's current state:
     *
     *  - PastDue / Suspended / Expired   → reactivate() (recover a lapse)
     *  - Initial + Pending               → activate() (first access granted)
     *  - Initial + (already active)      → no period change (trial conversion
     *                                      anchors its own period)
     *  - Renewal + Active / OnTrial      → advancePeriod() + SubscriptionRenewed
     *  - Proration / Usage / other       → no period change (M1 guard)
     *
     * Only a Renewal advances the period. Initial invoices activate (or, for
     * an already-active sub, do nothing); this replaces the previous "advance
     * on any paid invoice regardless of status" behavior.
     */
    protected function onPaid(Invoice $invoice): void
    {
        $subscription = $invoice->subscription()->first();

        if ($subscription) {
            $service = app(SubscriptionService::class);
            $status = $subscription->status;
            // Default to Renewal (the column default) for invoices created
            // without an explicit kind — host code or factories that insert a
            // bare invoice still route as a renewal.
            $kind = $invoice->kind ?? InvoiceKind::Renewal;

            $lapsed = [
                SubscriptionStatus::PastDue,
                SubscriptionStatus::Suspended,
                SubscriptionStatus::Expired,
            ];

            if (in_array($status, $lapsed, true)) {
                $service->reactivate($subscription, $invoice);
            } elseif ($kind === InvoiceKind::Initial) {
                // Activate a pending subscription; an already-active sub
                // (trial conversion) anchored its own period, so do nothing.
                if ($status === SubscriptionStatus::Pending) {
                    $service->activate($subscription, $invoice);
                }
            } elseif (
                $kind === InvoiceKind::Renewal
                && in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::OnTrial], true)
            ) {
                $subscription = $service->advancePeriod($subscription);
                $this->dispatchAfterCommit(fn () => SubscriptionRenewed::dispatch($subscription, $invoice));
            }
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
