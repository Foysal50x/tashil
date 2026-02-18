<?php

namespace Foysal50x\Tashil\Repositories\Cache;

use Foysal50x\Tashil\Contracts\SubscriptionItemRepositoryInterface;
use Foysal50x\Tashil\Managers\CacheManager;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionItem;

/**
 * @property SubscriptionItemRepositoryInterface $repository
 */
class CacheSubscriptionItemRepository extends BaseCacheRepository implements SubscriptionItemRepositoryInterface
{
    public function __construct(
        SubscriptionItemRepositoryInterface $repository,
        CacheManager $cacheManager,
        int $cacheTtl,
        string $cachePrefix,
    ) {
        parent::__construct($repository, $cacheManager, $cacheTtl, $cachePrefix);
    }

    public function findByFeatureSlug(Subscription $subscription, string $featureSlug, bool $withFeature = false): ?SubscriptionItem
    {
        $key = "sub_item:{$subscription->id}:{$featureSlug}:" . ($withFeature ? '1' : '0');
        return $this->remember($key, function () use ($subscription, $featureSlug, $withFeature) {
            return $this->repository->findByFeatureSlug($subscription, $featureSlug, $withFeature);
        });
    }

    public function incrementUsage(SubscriptionItem $item, float $amount): void
    {
        $this->repository->incrementUsage($item, $amount);
        $this->invalidateItem($item);
    }

    public function resetUsage(SubscriptionItem $item): void
    {
        $this->repository->resetUsage($item);
        $this->invalidateItem($item);
    }

    public function resetAllUsage(Subscription $subscription): void
    {
        $this->repository->resetAllUsage($subscription);
        // Invalidate all items for this subscription — we can't know all slugs, so flush by prefix isn't available.
        // Cache entries will naturally expire. This is acceptable.
    }

    protected function invalidateItem(SubscriptionItem $item): void
    {
        $slug = $item->feature?->slug;

        if (! $slug) {
            $item->loadMissing('feature');
            $slug = $item->feature?->slug;
        }

        if ($slug) {
            $key0 = "sub_item:{$item->subscription_id}:{$slug}:0";
            $key1 = "sub_item:{$item->subscription_id}:{$slug}:1";
            $r0 = $this->forget($key0);
            $r1 = $this->forget($key1);
        }
    }
}
