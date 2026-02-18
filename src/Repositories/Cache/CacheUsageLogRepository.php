<?php

namespace Foysal50x\Tashil\Repositories\Cache;

use Foysal50x\Tashil\Contracts\UsageLogRepositoryInterface;
use Foysal50x\Tashil\Managers\CacheManager;
use Foysal50x\Tashil\Models\UsageLog;

/**
 * @property UsageLogRepositoryInterface $repository
 */
class CacheUsageLogRepository extends BaseCacheRepository implements UsageLogRepositoryInterface
{
    public function __construct(
        UsageLogRepositoryInterface $repository,
        CacheManager $cacheManager,
        int $cacheTtl, // Shorter TTL for usage data
        string $cachePrefix,
    ) {
        parent::__construct($repository, $cacheManager, $cacheTtl, $cachePrefix);
    }

    public function create(array $data): UsageLog
    {
        $result = $this->repository->create($data);

        // Invalidate daily usage cache for this subscription+feature
        if (isset($data['subscription_id'], $data['feature_id'])) {
            $this->forget("usage_daily:{$data['subscription_id']}:{$data['feature_id']}");
        }

        return $result;
    }

    public function getDailyUsage(int $subscriptionId, int $featureId, int $days = 30): array
    {
        $key = "usage_daily:{$subscriptionId}:{$featureId}:{$days}";

        return $this->remember($key, fn () => $this->repository->getDailyUsage($subscriptionId, $featureId, $days));
    }
}
