<?php

namespace Foysal50x\Tashil\Services;

use Foysal50x\Tashil\Contracts\SubscriptionItemRepositoryInterface;
use Foysal50x\Tashil\Contracts\UsageLogRepositoryInterface;
use Foysal50x\Tashil\Enums\FeatureType;
use Foysal50x\Tashil\Events\UsageLimitWarning;
use Foysal50x\Tashil\Models\Subscription;

class UsageService
{
    public function __construct(
        protected SubscriptionItemRepositoryInterface $itemRepo,
        protected UsageLogRepositoryInterface $usageLogRepo,
    ) {}

    /**
     * Increment usage for a subscription feature.
     */
    public function increment(Subscription $subscription, string $featureSlug, float $amount = 1): bool
    {
        $item = $this->itemRepo->findByFeatureSlug($subscription, $featureSlug, withFeature: true);

        if (! $item) {
            return false;
        }

        $feature = $item->feature;

        // Check limits for 'limit' type features
        if ($feature->type === FeatureType::Limit) {
            $limit = (float) $item->value;
            if ($limit > 0 && ($item->usage + $amount) > $limit) {
                return false; // Limit exceeded
            }
        }

        // Increment usage atomically
        $this->itemRepo->incrementUsage($item, $amount);

        // Check for 80% usage warning (only fire once when crossing threshold)
        if ($feature->type === FeatureType::Limit || $feature->type === FeatureType::Consumable) {
            $limit = (float) $item->value;
            $previousUsage = $item->usage - $amount; // usage before this increment (already incremented above)
            $newUsage = (float) $item->usage;

            if ($limit > 0 && ($newUsage / $limit) >= 0.8 && ($previousUsage / $limit) < 0.8) {
                UsageLimitWarning::dispatch($subscription, $feature, $newUsage, $limit);
            }
        }

        // Log usage for audit trail
        $this->usageLogRepo->create([
            'subscription_id' => $subscription->id,
            'feature_id'      => $item->feature_id,
            'amount'          => $amount,
            'description'     => 'Usage increment',
        ]);

        return true;
    }

    /**
     * Check if a feature can be used (has access and within limits).
     */
    public function check(Subscription $subscription, string $featureSlug, float $amount = 1): bool
    {
        // Subscription must be valid
        if (! $subscription->isValid()) {
            return false;
        }

        $item = $this->itemRepo->findByFeatureSlug($subscription, $featureSlug, withFeature: true);

        if (! $item) {
            return false;
        }

        $feature = $item->feature;

        // Feature must be active
        if (! $feature->is_active) {
            return false;
        }

        if ($feature->type === FeatureType::Limit) {
            $limit = (float) $item->value;

            return $limit <= 0 || ($item->usage + $amount) <= $limit; // 0 = unlimited
        }

        if ($feature->type === FeatureType::Boolean) {
            return filter_var($item->value, FILTER_VALIDATE_BOOLEAN);
        }

        // Consumable and Enum types: access is granted if item exists
        return true;
    }

    /**
     * Reset usage for a specific feature on a subscription (useful for period resets).
     */
    public function resetUsage(Subscription $subscription, string $featureSlug): bool
    {
        $item = $this->itemRepo->findByFeatureSlug($subscription, $featureSlug);

        if (! $item) {
            return false;
        }

        $this->itemRepo->resetUsage($item);

        $this->usageLogRepo->create([
            'subscription_id' => $subscription->id,
            'feature_id'      => $item->feature_id,
            'amount'          => 0,
            'description'     => 'Usage reset',
        ]);

        return true;
    }

    /**
     * Reset all feature usages for a subscription.
     */
    public function resetAllUsage(Subscription $subscription): void
    {
        $this->itemRepo->resetAllUsage($subscription);
    }
}
