<?php

namespace Foysal50x\Tashil\Console;

use Carbon\Carbon;
use Foysal50x\Tashil\Contracts\InvoiceRepositoryInterface;
use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Managers\DatabaseManager;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

/**
 * Drives the dunning lifecycle for unpaid invoices past their due date:
 *
 *   Active --(retry milestone reached)--> PastDue
 *   PastDue --(attempts >= suspend_after)--> Suspended (access cut)
 *   Suspended --(+ cancel_after_suspend_days)--> Expired
 *
 * Tashil owns the state machine and fires SubscriptionPastDue / InvoiceOverdue
 * so the host can re-attempt the charge — Tashil never moves money itself.
 * Paying the invoice at any point recovers the subscription via
 * SubscriptionService::reactivate (InvoiceObserver).
 */
class ProcessDunningCommand extends Command
{
    protected $signature = 'tashil:process-dunning {--date= : Process as of a specific date (Y-m-d H:i:s)}';

    protected $description = 'Escalate unpaid overdue invoices through the dunning lifecycle';

    public function __construct(
        protected InvoiceRepositoryInterface $invoiceRepo,
        protected SubscriptionService $subscriptionService,
        protected DatabaseManager $db,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! Config::get('tashil.dunning.enabled', true)) {
            $this->info('Dunning is disabled (tashil.dunning.enabled=false).');

            return self::SUCCESS;
        }

        $moment = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::now();

        $retryDays = array_map('intval', (array) Config::get('tashil.dunning.retry_days', [1, 3, 5]));
        sort($retryDays);
        $suspendAfter = (int) Config::get('tashil.dunning.suspend_after_attempts', 3);
        $cancelAfterDays = (int) Config::get('tashil.dunning.cancel_after_suspend_days', 7);

        $invoices = $this->invoiceRepo->dueForDunning($moment);

        $this->info("Processing dunning for {$invoices->count()} overdue invoice(s) as of {$moment->toDateTimeString()}");

        $processed = 0;
        $failed = 0;
        $failedIds = [];

        foreach ($invoices as $invoice) {
            try {
                $this->processOne($invoice, $moment, $retryDays, $suspendAfter, $cancelAfterDays);
                $processed++;
            } catch (\Throwable $e) {
                $failed++;
                $failedIds[] = $invoice->id;
                Log::error("tashil:process-dunning failed for invoice {$invoice->id}: " . $e->getMessage());
            }
        }

        // Each invoice commits in its own transaction, so the successes above are
        // durable regardless of the failures. The exit code is a monitoring signal,
        // not a transaction outcome: a non-zero exit on any failure lets the
        // scheduler / cron monitor surface an invoice that is persistently stuck in
        // dunning (a subscriber not being escalated is a revenue leak we must not
        // hide). A true transient self-heals on the next 30-minute run.
        $this->info("Dunning complete: {$processed} processed, {$failed} failed of {$invoices->count()}.");

        if ($failed > 0) {
            $this->error('Failed invoice id(s): ' . implode(', ', $failedIds) . ' — see logs.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Each invoice is processed under a per-subscription lock so concurrent
     * dunning runs and renewals serialize on the same subscription row.
     */
    protected function processOne(Invoice $invoice, Carbon $moment, array $retryDays, int $suspendAfter, int $cancelAfterDays): void
    {
        $this->db->connection()->transaction(function () use ($invoice, $moment, $retryDays, $suspendAfter, $cancelAfterDays) {
            $subscription = Subscription::query()
                ->where('id', $invoice->subscription_id)
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                return;
            }

            $dunnable = [SubscriptionStatus::Active, SubscriptionStatus::PastDue, SubscriptionStatus::Suspended];
            if (! in_array($subscription->status, $dunnable, true)) {
                return;
            }

            // Re-check the invoice is still unpaid under the lock — the host
            // may have collected payment between the scan and here.
            $invoice = Invoice::query()->find($invoice->id);
            if (! $invoice || $invoice->status !== InvoiceStatus::Pending) {
                return;
            }

            $daysSinceDue = $this->daysSinceDue($invoice, $moment);
            $attempt = $this->milestonesPassed($retryDays, $daysSinceDue);

            // Enter or advance the past-due cycle on each new retry milestone.
            if (in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::PastDue], true) && $attempt >= 1) {
                if ($subscription->status === SubscriptionStatus::Active || $attempt > $subscription->dunning_attempts) {
                    $subscription = $this->subscriptionService->markPastDue($subscription, $invoice, $attempt);
                }
            }

            // Suspend (cut access) once retries are exhausted.
            if ($subscription->status === SubscriptionStatus::PastDue && $attempt >= $suspendAfter) {
                $subscription = $this->subscriptionService->suspend($subscription);
            }

            // Expire after the suspension grace window.
            if (
                $subscription->status === SubscriptionStatus::Suspended
                && $subscription->suspended_at
                && $moment->greaterThanOrEqualTo($subscription->suspended_at->copy()->addDays($cancelAfterDays))
            ) {
                $this->subscriptionService->expire($subscription);
            }
        });
    }

    /**
     * Whole days elapsed since the invoice's due date. Computed from
     * timestamps so it is stable across the Carbon 2 / 3 diffInDays
     * signature differences between supported Laravel versions.
     */
    protected function daysSinceDue(Invoice $invoice, Carbon $moment): int
    {
        $dueTs = $invoice->due_date->getTimestamp();

        return (int) floor(max(0, $moment->getTimestamp() - $dueTs) / 86400);
    }

    /**
     * How many retry milestones (config dunning.retry_days) have elapsed.
     */
    protected function milestonesPassed(array $retryDays, int $daysSinceDue): int
    {
        $count = 0;
        foreach ($retryDays as $day) {
            if ($daysSinceDue >= $day) {
                $count++;
            }
        }

        return $count;
    }
}
