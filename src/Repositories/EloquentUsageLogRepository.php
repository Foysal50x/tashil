<?php

namespace Foysal50x\Tashil\Repositories;

use Foysal50x\Tashil\Contracts\UsageLogRepositoryInterface;
use Foysal50x\Tashil\Models\UsageLog;
use Tpetry\QueryExpressions\Function\Aggregate\Sum;
use Tpetry\QueryExpressions\Language\Alias;

class EloquentUsageLogRepository implements UsageLogRepositoryInterface
{
    public function create(array $data): UsageLog
    {
        return UsageLog::create($data);
    }

    public function getDailyUsage(int $subscriptionId, int $featureId, int $days = 30): array
    {
        return UsageLog::query()
            ->where('subscription_id', $subscriptionId)
            ->where('feature_id', $featureId)
            ->where('created_at', '>=', now()->subDays($days))
            ->select([
                new Alias(new \Tpetry\QueryExpressions\Function\DTime\Date('created_at'), 'date'),
                new Alias(new Sum('amount'), 'total'),
            ])
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();
    }
}
