<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Contracts;

use Foysal50x\Tashil\Models\FeatureUsage;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Database\Eloquent\Collection;

interface FeatureUsageRepositoryInterface
{
    public function findBySlug(Subscription $subscription, string $featureSlug, bool $withFeature = false): ?FeatureUsage;

    public function insert(array $data): FeatureUsage;

    /**
     * Atomically increment usage iff (limit_value IS NULL OR usage + amount <= limit_value).
     *
     * @return float|null the new usage value when the row was updated, null when the
     *                    update was rejected (limit would be exceeded) or the row was missing.
     */
    public function atomicIncrement(FeatureUsage $usage, float $amount): ?float;

    public function resetUsage(FeatureUsage $usage): void;

    public function resetAllUsage(Subscription $subscription): void;

    /**
     * Fetch counter rows whose period has elapsed and whose reset_period is not 'never'.
     *
     * @return Collection<int, FeatureUsage>
     */
    public function dueForReset(\DateTimeInterface $now): Collection;

    /**
     * Stream due-for-reset rows in chunks of $chunkSize. The callback
     * receives each chunk as a Collection. Used by the Resetter to
     * batch many rows under one transaction without loading everything
     * into memory.
     *
     * @param  callable(Collection<int, FeatureUsage>): void  $callback
     */
    public function chunkDueForReset(\DateTimeInterface $now, int $chunkSize, callable $callback): void;

    /**
     * Set absolute usage (used for storage-style features).
     *
     * @return float the new usage value.
     */
    public function reportAbsolute(FeatureUsage $usage, float $amount): float;

    /**
     * Re-anchor every counter's reset window to start at $now. Used on
     * activation so the first quota period aligns with the moment access
     * actually begins (first payment) rather than the earlier subscribe time.
     */
    public function reanchorPeriods(Subscription $subscription, \DateTimeInterface $now): void;
}
