<?php

namespace Foysal50x\Tashil\Console;

use Carbon\Carbon;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Events\TrialEnding;
use Foysal50x\Tashil\Services\EventStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MarkTrialsEndingCommand extends Command
{
    protected $signature = 'tashil:mark-trials-ending {--date= : Process for a specific date (Y-m-d H:i:s)}';

    protected $description = 'Dispatch TrialEnding for trials approaching their expiry';

    public function __construct(
        protected SubscriptionRepositoryInterface $subscriptionRepo,
        protected EventStore $eventStore,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $now = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::now();
        $warnDays = (int) Config::get('tashil.trial.warn_days', 3);

        $due = $this->subscriptionRepo->trialsEndingSoon($now, $warnDays);

        $this->info("Notifying {$due->count()} ending trial(s) (warn_days={$warnDays})");

        foreach ($due as $subscription) {
            try {
                $daysRemaining = $now->diffInDays($subscription->trial_ends_at, false);
                $idempotencyKey = "trial-ending:{$subscription->id}:" . $now->format('Y-m-d');

                $this->eventStore->append(
                    $subscription,
                    'trial.ending',
                    ['days_remaining' => $daysRemaining],
                    idempotencyKey: $idempotencyKey,
                );

                if (Config::get('tashil.events.async', true)) {
                    DB::afterCommit(fn () => TrialEnding::dispatch($subscription, $daysRemaining));
                } else {
                    TrialEnding::dispatch($subscription, $daysRemaining);
                }
            } catch (\Throwable $e) {
                Log::error("tashil:mark-trials-ending failed for {$subscription->id}: " . $e->getMessage());
            }
        }

        return Command::SUCCESS;
    }
}
