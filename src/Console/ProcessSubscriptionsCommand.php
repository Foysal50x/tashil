<?php

namespace Foysal50x\Tashil\Console;

use Carbon\Carbon;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Services\BillingService;
use Foysal50x\Tashil\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessSubscriptionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tashil:process-subscriptions {--date= : Process for a specific date (Y-m-d)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process subscription renewals and expirations';

    public function __construct(
        protected SubscriptionRepositoryInterface $subscriptionRepo,
        protected SubscriptionService $subscriptionService,
        protected BillingService $billingService,
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $date = $this->option('date') 
            ? Carbon::parse($this->option('date')) 
            : Carbon::today();

        $this->info("Processing subscriptions for {$date->toDateString()}");

        $this->processRenewals($date);
        $this->processExpirations($date);

        $this->info('Subscription processing completed.');

        return Command::SUCCESS;
    }

    protected function processRenewals(Carbon $date): void
    {
        $this->info('Processing renewals...');

        $expiring = $this->subscriptionRepo->getExpiringSubscriptions($date, autoRenew: true);

        $this->withProgressBar($expiring, function (\Foysal50x\Tashil\Models\Subscription $subscription) {
            try {
                // Check if there is already a pending invoice
                if ($subscription->invoices()->pending()->exists()) {
                    // User Rule: If pending invoice exists, cancel subscription instead of creating new invoice
                    $this->subscriptionService->cancel($subscription, immediate: false, reason: 'Auto-renewal failed: Pending invoice exists');
                    Log::info("Cancelled subscription {$subscription->id} due to existing pending invoice.");
                    return;
                }

                // Create renewal invoice
                $this->billingService->generateInvoice($subscription);
                
                Log::info("Generated renewal invoice for subscription {$subscription->id}");

            } catch (\Throwable $e) {
                Log::error("Failed to process renewal for subscription {$subscription->id}: " . $e->getMessage());
            }
        });

        $this->newLine();
    }

    protected function processExpirations(Carbon $date): void
    {
        $this->info('Processing expirations...');

        $expiring = $this->subscriptionRepo->getExpiringSubscriptions($date, autoRenew: false);

        $this->withProgressBar($expiring, function (\Foysal50x\Tashil\Models\Subscription $subscription) {
            try {
                // Determine logic: 
                // If auto_renew is false, we just mark it as expired/cancelled effectively.
                // The Subscription model has `isExpired()` check which checks ends_at < now().
                // But we might want to explicitly set status to Expired if that's the preferred state flow.
                // The `cancel` method sets status to Cancelled. 
                // Let's assume extending the logic to set status to Expired if not already.

                if ($subscription->status !== SubscriptionStatus::Expired) {
                     $this->subscriptionRepo->update($subscription, ['status' => SubscriptionStatus::Expired]);
                     Log::info("Marked subscription {$subscription->id} as expired.");
                }

            } catch (\Throwable $e) {
                Log::error("Failed to process expiration for subscription {$subscription->id}: " . $e->getMessage());
            }
        });

        $this->newLine();
    }
}
