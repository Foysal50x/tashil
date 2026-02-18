<?php

namespace Foysal50x\Tashil\Contracts;

use Foysal50x\Tashil\Models\UsageLog;

interface UsageLogRepositoryInterface
{
    /**
     * Create a new usage log entry.
     */
    public function create(array $data): UsageLog;

    /**
     * Get daily usage aggregates for a subscription + feature.
     */
    public function getDailyUsage(int $subscriptionId, int $featureId, int $days = 30): array;
}
