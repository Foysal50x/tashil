<?php

namespace Foysal50x\Tashil\Services;

use Foysal50x\Tashil\Contracts\FeatureUsageRepositoryInterface;
use Foysal50x\Tashil\Contracts\UsageLogRepositoryInterface;
use Foysal50x\Tashil\Enums\UsageOperation;
use Foysal50x\Tashil\Events\UsageReset;
use Foysal50x\Tashil\Managers\DatabaseManager;
use Foysal50x\Tashil\Models\FeatureUsage;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resets feature_usages whose period_end has elapsed. Period windows
 * are anchored to the previous period_end (not to now()) so a delayed
 * cron run does not drift the reset cadence.
 *
 * Rows are processed in chunks, one transaction per chunk. A failed
 * row rolls back its chunk; subsequent chunks continue so a single
 * pathological row can't block the cron. The event-store idempotency
 * key is "usage-reset:{usage_id}:{Y-m-d-H}" so a chunk re-run within
 * the same hour dedupes the event-store appends.
 */
class Resetter
{
    public const DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        protected DatabaseManager $db,
        protected FeatureUsageRepositoryInterface $usageRepo,
        protected UsageLogRepositoryInterface $logRepo,
        protected EventStore $eventStore,
    ) {}

    public function resetDueQuotas(?\DateTimeInterface $now = null, ?int $batchSize = null): int
    {
        $now = $now ?? now();
        $batchSize = $batchSize ?? (int) Config::get('tashil.usage.reset_batch_size', self::DEFAULT_BATCH_SIZE);
        $resetCount = 0;

        $this->usageRepo->chunkDueForReset($now, $batchSize, function ($chunk) use ($now, &$resetCount) {
            try {
                $this->db->connection()->transaction(function () use ($chunk, $now, &$resetCount) {
                    foreach ($chunk as $usage) {
                        $this->resetOne($usage, $now);
                        $resetCount++;
                    }
                });
            } catch (Throwable $e) {
                Log::error('tashil:reset-quotas chunk failed and was rolled back', [
                    'batch_first_id' => $chunk->first()?->id,
                    'batch_last_id'  => $chunk->last()?->id,
                    'batch_size'     => $chunk->count(),
                    'exception'      => $e->getMessage(),
                ]);
            }
        });

        return $resetCount;
    }

    protected function resetOne(FeatureUsage $usage, \DateTimeInterface $now): void
    {
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
