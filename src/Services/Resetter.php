<?php

namespace Foysal50x\Tashil\Services;

use Foysal50x\Tashil\Contracts\FeatureUsageRepositoryInterface;
use Foysal50x\Tashil\Contracts\UsageLogRepositoryInterface;
use Foysal50x\Tashil\Enums\UsageOperation;
use Foysal50x\Tashil\Events\UsageReset;
use Foysal50x\Tashil\Managers\DatabaseManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Resets feature_usages whose period_end has elapsed. Period windows
 * are anchored to the previous period_end (not to now()) so a delayed
 * cron run does not drift the reset cadence.
 */
class Resetter
{
    public function __construct(
        protected DatabaseManager $db,
        protected FeatureUsageRepositoryInterface $usageRepo,
        protected UsageLogRepositoryInterface $logRepo,
        protected EventStore $eventStore,
    ) {}

    public function resetDueQuotas(?\DateTimeInterface $now = null): int
    {
        $now = $now ?? now();
        $resetCount = 0;

        foreach ($this->usageRepo->dueForReset($now) as $usage) {
            $this->db->connection()->transaction(function () use ($usage, $now) {
                $previousUsage = (float) $usage->usage;

                $this->usageRepo->resetUsage($usage);

                $this->logRepo->create([
                    'subscription_id' => $usage->subscription_id,
                    'feature_id'      => $usage->feature_id,
                    'operation'       => UsageOperation::Reset->value,
                    'amount'          => 0,
                    'previous_usage'  => $previousUsage,
                    'new_usage'       => 0,
                    'description'     => 'Scheduled quota reset',
                ]);

                $subscription = $usage->subscription;
                if (! $subscription) {
                    return;
                }

                $this->eventStore->append(
                    $subscription,
                    'usage.reset',
                    [
                        'feature_id'     => $usage->feature_id,
                        'previous_usage' => $previousUsage,
                    ],
                    idempotencyKey: "usage-reset:{$usage->id}:" . $now->format('Y-m-d-H'),
                );

                if ($usage->feature) {
                    $this->dispatchAfterCommit(fn () => UsageReset::dispatch($subscription, $usage->feature, $previousUsage));
                }
            });

            $resetCount++;
        }

        return $resetCount;
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
