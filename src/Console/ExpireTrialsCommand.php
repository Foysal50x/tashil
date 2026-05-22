<?php

namespace Foysal50x\Tashil\Console;

use Carbon\Carbon;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireTrialsCommand extends Command
{
    protected $signature = 'tashil:expire-trials {--date= : Process for a specific date (Y-m-d H:i:s)}';

    protected $description = 'Mark trials whose trial_ends_at has passed without conversion as Expired';

    public function __construct(
        protected SubscriptionRepositoryInterface $subscriptionRepo,
        protected SubscriptionService $subscriptionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $moment = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::now();

        $due = $this->subscriptionRepo->dueForTrialExpiration($moment);

        $this->info("Expiring {$due->count()} trial(s) for {$moment->toDateTimeString()}");

        $hadFailures = false;

        foreach ($due as $subscription) {
            try {
                $this->subscriptionService->expireTrial($subscription);
            } catch (\Throwable $e) {
                $hadFailures = true;
                Log::error("tashil:expire-trials failed for {$subscription->id}: " . $e->getMessage());
            }
        }

        return $hadFailures ? Command::FAILURE : Command::SUCCESS;
    }
}
