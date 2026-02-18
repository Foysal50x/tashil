<?php

namespace Foysal50x\Tashil\Repositories;

use Foysal50x\Tashil\Contracts\SubscriptionItemRepositoryInterface;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionItem;

class EloquentSubscriptionItemRepository implements SubscriptionItemRepositoryInterface
{
    public function findByFeatureSlug(Subscription $subscription, string $featureSlug, bool $withFeature = false): ?SubscriptionItem
    {
        $query = $subscription->items()
            ->whereHas('feature', fn ($q) => $q->where('slug', $featureSlug));

        if ($withFeature) {
            $query->with('feature');
        }

        return $query->first();
    }

    public function incrementUsage(SubscriptionItem $item, float $amount): void
    {
        $item->increment('usage', $amount);
    }

    public function resetUsage(SubscriptionItem $item): void
    {
        $item->update(['usage' => 0]);
    }

    public function resetAllUsage(Subscription $subscription): void
    {
        $subscription->items()->update(['usage' => 0]);
    }
}
