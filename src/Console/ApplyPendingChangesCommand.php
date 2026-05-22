<?php

namespace Foysal50x\Tashil\Console;

use Carbon\Carbon;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ApplyPendingChangesCommand extends Command
{
    protected $signature = 'tashil:apply-pending-changes {--date= : Process for a specific date (Y-m-d H:i:s)}';

    protected $description = 'Apply previously scheduled package changes whose effective time has arrived';

    public function __construct(
        protected SubscriptionRepositoryInterface $subscriptionRepo,
        protected SubscriptionService $subscriptionService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $moment = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::now();

        $due = $this->subscriptionRepo->dueForPendingChange($moment);

        $this->info("Applying {$due->count()} pending change(s) for {$moment->toDateTimeString()}");

        foreach ($due as $subscription) {
            try {
                $this->subscriptionService->applyPendingChange($subscription);
            } catch (\Throwable $e) {
                Log::error("tashil:apply-pending-changes failed for {$subscription->id}: " . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
