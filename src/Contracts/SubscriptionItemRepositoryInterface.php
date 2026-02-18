<?php

namespace Foysal50x\Tashil\Contracts;

use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionItem;

interface SubscriptionItemRepositoryInterface
{
    /**
     * Find a subscription item by feature slug.
     *
     * @param  bool  $withFeature  Eager-load the feature relationship
     */
    public function findByFeatureSlug(Subscription $subscription, string $featureSlug, bool $withFeature = false): ?SubscriptionItem;

    /**
     * Increment usage on a subscription item.
     */
    public function incrementUsage(SubscriptionItem $item, float $amount): void;

    /**
     * Reset usage for a specific feature item.
     */
    public function resetUsage(SubscriptionItem $item): void;

    /**
     * Reset all feature usages for a subscription.
     */
    public function resetAllUsage(Subscription $subscription): void;
}
