<?php

namespace Foysal50x\Tashil\Console;

use Carbon\Carbon;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Managers\DatabaseManager;
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
        protected DatabaseManager $db,
    ) {
        parent::__construct();
    }

    private const ALLOWED_PENDING_INVOICE_POLICIES = ['cancel', 'skip', 'extend_grace'];

    public function handle(): int
    {
        $moment = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::now();

        $policy = Config::get('tashil.renewal.on_pending_invoice', 'cancel');

        if (! in_array($policy, self::ALLOWED_PENDING_INVOICE_POLICIES, true)) {
            $this->error("Invalid tashil.renewal.on_pending_invoice value '{$policy}'. Allowed: " . implode(', ', self::ALLOWED_PENDING_INVOICE_POLICIES));

            return Command::FAILURE;
        }

        $due = $this->subscriptionRepo->dueForRenewal($moment);

        $this->info("Renewing {$due->count()} subscription(s) for {$moment->toDateTimeString()} (on_pending_invoice={$policy})");

        $hadFailures = false;

        foreach ($due as $subscription) {
            try {
                $this->renewOne($subscription, $policy);
            } catch (\Throwable $e) {
                $hadFailures = true;
                Log::error("tashil:renew-subscriptions failed for {$subscription->id}: " . $e->getMessage());
            }
        }

        return $hadFailures ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Each renewal locks the subscription row and re-verifies its
     * status before billing. Between dueForRenewal() and the lock,
     * another process may have cancelled, paused, or suspended the
     * subscription — generating an invoice for that subscription would
     * be a real money error, so we bail under the lock instead.
     */
    protected function renewOne(Subscription $subscription, string $policy): void
    {
        $this->db->connection()->transaction(function () use ($subscription, $policy) {
            $locked = Subscription::query()
                ->where('id', $subscription->id)
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                Log::info("Skipping renewal for {$subscription->id}: subscription no longer exists");

                return;
            }

            if (! $this->stillRenewable($locked)) {
                Log::info("Skipping renewal for {$subscription->id}: status '{$locked->status->value}' / auto_renew={$locked->auto_renew} no longer qualifies");

                return;
            }

            $hasPending = $locked->invoices()->where('status', 'pending')->exists();

            if ($hasPending) {
                match ($policy) {
                    'skip'         => Log::info("Skipping renewal for {$locked->id}: pending invoice exists"),
                    'extend_grace' => $this->extendGrace($locked),
                    'cancel'       => $this->subscriptionService->cancel(
                        $locked,
                        immediate: false,
                        reason: 'Auto-renewal failed: pending invoice exists',
                    ),
                };

                return;
            }

            $this->billingService->generateInvoice($locked);
            Log::info("tashil:renew-subscriptions issued renewal invoice for {$locked->id}");
        });
    }

    protected function stillRenewable(Subscription $subscription): bool
    {
        if (! $subscription->auto_renew) {
            return false;
        }

        // Active only — a trial is billed at conversion, never by the
        // renewal cron, so OnTrial never qualifies here.
        return $subscription->status === SubscriptionStatus::Active;
    }

    protected function extendGrace(Subscription $subscription): void
    {
        $max = (int) Config::get('tashil.renewal.max_grace_extensions', 3);

        // Without a cap, a never-paid invoice would extend the period on
        // every run forever. dunning_attempts is reused as the extension
        // counter; once exhausted we stop extending and let the dunning /
        // expire jobs take the subscription down.
        if ($subscription->dunning_attempts >= $max) {
            Log::info("tashil:renew-subscriptions grace exhausted for {$subscription->id} (>= {$max} extensions); leaving to dunning");

            return;
        }

        $days = (int) Config::get('tashil.renewal.grace_days', 3);
        $newEnd = ($subscription->current_period_end ?? now())->copy()->addDays($days);

        $subscription->update([
            'current_period_end' => $newEnd,
            'dunning_attempts'   => $subscription->dunning_attempts + 1,
        ]);

        Log::info("tashil:renew-subscriptions extended grace for {$subscription->id} until {$newEnd}");
    }
}
