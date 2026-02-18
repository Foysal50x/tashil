<?php

namespace Foysal50x\Tashil\Repositories\Cache;

use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Managers\CacheManager;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Database\Eloquent\Model;

/**
 * @property SubscriptionRepositoryInterface $repository
 */
class CacheSubscriptionRepository extends BaseCacheRepository implements SubscriptionRepositoryInterface
{
    public function __construct(
        SubscriptionRepositoryInterface $repository,
        CacheManager $cacheManager,
        int $cacheTtl,
        string $cachePrefix,
    ) {
        parent::__construct($repository, $cacheManager, $cacheTtl, $cachePrefix);
    }

    // ── Write-through (invalidate cache) ────────────────────────

    public function create(array $data): Subscription
    {
        $result = $this->repository->create($data);
        $this->invalidateSubscriberCache($result->subscriber_type, $result->subscriber_id);
        $this->invalidateAggregates();

        return $result;
    }

    public function update(Subscription $subscription, array $data): Subscription
    {
        $result = $this->repository->update($subscription, $data);
        $this->invalidateSubscriberCache($subscription->subscriber_type, $subscription->subscriber_id);
        $this->invalidateAggregates();

        return $result;
    }

    public function syncFeatureItems(Subscription $subscription, Package $package): void
    {
        $this->repository->syncFeatureItems($subscription, $package);
    }

    // ── Cached reads ────────────────────────────────────────────

    public function findById(int $id): ?Subscription
    {
        $key = "subscription:{$id}";

        return $this->remember($key, fn () => $this->repository->findById($id));
    }

    public function findValidForSubscriber(Model $subscriber): ?Subscription
    {
        $key = $this->subscriberKey($subscriber, 'valid');

        return $this->remember($key, fn () => $this->repository->findValidForSubscriber($subscriber));
    }

    public function subscriberHasValidSubscription(Model $subscriber, Package|string|null $package = null): bool
    {
        $suffix = $package instanceof Package ? "has:{$package->id}" : "has:" . ($package ?? 'any');
        $key = $this->subscriberKey($subscriber, $suffix);

        return $this->remember($key, function () use ($subscriber, $package) {
            return $this->repository->subscriberHasValidSubscription($subscriber, $package);
        });
    }

    public function findCancelledResumable(Model $subscriber): ?Subscription
    {
        // Not cached — rare operation
        return $this->repository->findCancelledResumable($subscriber);
    }

    public function getExpiringSubscriptions(\DateTimeInterface $date, ?bool $autoRenew = null): \Illuminate\Database\Eloquent\Collection
    {
        return $this->repository->getExpiringSubscriptions($date, $autoRenew);
    }

    // ── Cached aggregates ───────────────────────────────────────

    public function activeCount(): int
    {
        $key = 'subscription:aggregate:active_count';

        return $this->remember($key, fn () => $this->repository->activeCount());
    }

    public function churnedCount(\DateTimeInterface $since): int
    {
        return $this->repository->churnedCount($since);
    }

    public function totalCountInPeriod(\DateTimeInterface $since): int
    {
        return $this->repository->totalCountInPeriod($since);
    }

    public function calculateMRR(): float
    {
        $key = 'subscription:aggregate:mrr';

        return $this->remember($key, fn () => $this->repository->calculateMRR());
    }

    public function totalCount(): int
    {
        $key = 'subscription:aggregate:total_count';

        return $this->remember($key, fn () => $this->repository->totalCount());
    }

    public function countByStatus(): array
    {
        $key = 'subscription:aggregate:count_by_status';

        return $this->remember($key, fn () => $this->repository->countByStatus());
    }

    public function trialConversionRate(): float
    {
        return $this->repository->trialConversionRate();
    }

    public function newSubscriptionsPerPeriod(int $months = 12): array
    {
        return $this->repository->newSubscriptionsPerPeriod($months);
    }

    public function subscribersByPackage(): array
    {
        $key = 'subscription:aggregate:by_package';

        return $this->remember($key, fn () => $this->repository->subscribersByPackage());
    }

    public function revenueByPackage(): array
    {
        return $this->repository->revenueByPackage();
    }

    public function churnTrend(int $months = 12, int $windowDays = 30): array
    {
        return $this->repository->churnTrend($months, $windowDays);
    }

    public function dashboardStats(): array
    {
        $key = 'subscription:aggregate:dashboard_stats';

        return $this->remember($key, fn () => $this->repository->dashboardStats());
    }

    public function analyticsByPackage(): array
    {
        $key = 'subscription:aggregate:analytics_by_package';

        return $this->remember($key, fn () => $this->repository->analyticsByPackage());
    }

    // ── Internal ────────────────────────────────────────────────

    protected function subscriberKey(Model $subscriber, string $suffix): string
    {
        return 'subscription:' . class_basename($subscriber) . ":{$subscriber->getKey()}:{$suffix}";
    }

    protected function invalidateSubscriberCache(string $type, int|string $id): void
    {
        $base = class_basename($type);
        $key1 = "subscription:{$base}:{$id}:valid";
        $key2 = "subscription:{$base}:{$id}:has:any";

        $this->forget($key1);
        $this->forget($key2);
    }

    protected function invalidateAggregates(): void
    {
        $this->forget('subscription:aggregate:active_count');
        $this->forget('subscription:aggregate:mrr');
        $this->forget('subscription:aggregate:total_count');
        $this->forget('subscription:aggregate:count_by_status');
        $this->forget('subscription:aggregate:by_package');
        $this->forget('subscription:aggregate:dashboard_stats');
        $this->forget('subscription:aggregate:analytics_by_package');
    }
}
