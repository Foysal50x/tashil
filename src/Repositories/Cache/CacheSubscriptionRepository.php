<?php

namespace Foysal50x\Tashil\Repositories\Cache;

use Foysal50x\Tashil\Contracts\Subscribable;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Managers\CacheManager;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Database\Eloquent\Collection;

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

    public function create(array $data): Subscription
    {
        $result = $this->repository->create($data);
        $this->invalidateSubscriberCache($result);
        $this->invalidateAggregates();

        return $result;
    }

    public function update(Subscription $subscription, array $data): Subscription
    {
        $previousPackageId = $subscription->getOriginal('package_id');
        $result = $this->repository->update($subscription, $data);
        $this->invalidateSubscriberCache($result, $previousPackageId);
        $this->invalidateAggregates();

        return $result;
    }

    public function syncFeatures(Subscription $subscription, Package $package): void
    {
        $this->repository->syncFeatures($subscription, $package);
    }

    public function findById(int $id): ?Subscription
    {
        $key = "subscription:{$id}";

        return $this->remember($key, fn () => $this->repository->findById($id));
    }

    public function findValidForSubscriber(Subscribable $subscriber): ?Subscription
    {
        $key = $this->subscriberKey($subscriber, 'valid');

        return $this->remember($key, fn () => $this->repository->findValidForSubscriber($subscriber));
    }

    public function subscriberHasValidSubscription(Subscribable $subscriber, Package|string|null $package = null): bool
    {
        $suffix = $package instanceof Package ? "has:{$package->id}" : 'has:' . ($package ?? 'any');
        $key = $this->subscriberKey($subscriber, $suffix);

        return $this->remember($key, function () use ($subscriber, $package) {
            return $this->repository->subscriberHasValidSubscription($subscriber, $package);
        });
    }

    public function findCancelledResumable(Subscribable $subscriber): ?Subscription
    {
        // Not cached — rare operation
        return $this->repository->findCancelledResumable($subscriber);
    }

    public function dueForRenewal(\DateTimeInterface $moment): Collection
    {
        return $this->repository->dueForRenewal($moment);
    }

    public function dueForExpiration(\DateTimeInterface $moment): Collection
    {
        return $this->repository->dueForExpiration($moment);
    }

    public function dueForTrialExpiration(\DateTimeInterface $moment): Collection
    {
        return $this->repository->dueForTrialExpiration($moment);
    }

    public function trialsEndingSoon(\DateTimeInterface $now, int $warnDays): Collection
    {
        return $this->repository->trialsEndingSoon($now, $warnDays);
    }

    public function dueForPendingChange(\DateTimeInterface $moment): Collection
    {
        return $this->repository->dueForPendingChange($moment);
    }

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

    protected function subscriberKey(Subscribable $subscriber, string $suffix): string
    {
        return 'subscription:'
            . class_basename($subscriber->getSubscriberType())
            . ":{$subscriber->getSubscriberKey()}:{$suffix}";
    }

    /**
     * Forget the cache entries that depend on the subscriber's current
     * subscription state. We can't pattern-scan the cache for every
     * `has:{package_id}` key that was ever queried, so we forget the
     * package the subscription is now on AND the package it just moved
     * off of (for plan switches). Cross-package has:Z queries for
     * packages this subscription never touched will stale until TTL,
     * which is acceptable for an answer that was always "false".
     */
    protected function invalidateSubscriberCache(Subscription $subscription, int|string|null $previousPackageId = null): void
    {
        $base = class_basename($subscription->subscriber_type);
        $id = $subscription->subscriber_id;

        $this->forget("subscription:{$base}:{$id}:valid");
        $this->forget("subscription:{$base}:{$id}:has:any");

        $packageIds = array_filter(
            array_unique([$subscription->package_id, $previousPackageId]),
            fn ($v) => $v !== null,
        );

        foreach ($packageIds as $packageId) {
            $this->forget("subscription:{$base}:{$id}:has:{$packageId}");
        }

        // subscriberHasValidSubscription also accepts a string slug; cover
        // the slug-keyed entries for the current package. Previous slugs
        // can't be resolved without re-hydrating the deleted relation, so
        // they're left to TTL (uncommon path).
        $currentSlug = $subscription->package?->slug;
        if (is_string($currentSlug) && $currentSlug !== '') {
            $this->forget("subscription:{$base}:{$id}:has:{$currentSlug}");
        }
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
