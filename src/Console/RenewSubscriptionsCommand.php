<?php

namespace Foysal50x\Tashil\Console;

use Carbon\Carbon;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Services\BillingService;
use Foysal50x\Tashil\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class RenewSubscriptionsCommand extends Command
{
    protected $signature = 'tashil:renew-subscriptions {--date= : Process for a specific date (Y-m-d H:i:s)}';

    protected $description = 'Generate renewal invoices for subscriptions whose billing period has elapsed';

    public function __construct(
        protected SubscriptionRepositoryInterface $subscriptionRepo,
        protected SubscriptionService $subscriptionService,
        protected BillingService $billingService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $moment = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::now();

        $policy = Config::get('tashil.renewal.on_pending_invoice', 'cancel');

        $due = $this->subscriptionRepo->dueForRenewal($moment);

        $this->info("Renewing {$due->count()} subscription(s) for {$moment->toDateTimeString()} (on_pending_invoice={$policy})");

        foreach ($due as $subscription) {
            try {
                $this->renewOne($subscription, $policy);
            } catch (\Throwable $e) {
                Log::error("tashil:renew-subscriptions failed for {$subscription->id}: " . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }

    protected function renewOne(Subscription $subscription, string $policy): void
    {
        $hasPending = $subscription->invoices()->where('status', 'pending')->exists();

        if ($hasPending) {
            match ($policy) {
                'skip'         => Log::info("Skipping renewal for {$subscription->id}: pending invoice exists"),
                'extend_grace' => $this->extendGrace($subscription),
                default        => $this->subscriptionService->cancel(
                    $subscription,
                    immediate: false,
                    reason: 'Auto-renewal failed: pending invoice exists',
                ),
            };

            return;
        }

        $this->billingService->generateInvoice($subscription);
        Log::info("tashil:renew-subscriptions issued renewal invoice for {$subscription->id}");
    }

    protected function extendGrace(Subscription $subscription): void
    {
        $days = (int) Config::get('tashil.renewal.grace_days', 3);
        $newEnd = ($subscription->current_period_end ?? now())->copy()->addDays($days);

        $subscription->update(['current_period_end' => $newEnd]);

        Log::info("tashil:renew-subscriptions extended grace for {$subscription->id} until {$newEnd}");
    }
}
