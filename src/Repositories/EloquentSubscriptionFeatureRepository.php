<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Repositories;

use Foysal50x\Tashil\Contracts\SubscriptionFeatureRepositoryInterface;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionFeature;
use Illuminate\Database\Eloquent\Collection;

class EloquentSubscriptionFeatureRepository implements SubscriptionFeatureRepositoryInterface
{
    public function current(Subscription $subscription): Collection
    {
        return SubscriptionFeature::query()
            ->where('subscription_id', $subscription->id)
            ->whereNull('superseded_at')
            ->get();
    }

    public function findCurrentBySlug(Subscription $subscription, string $featureSlug): ?SubscriptionFeature
    {
        return SubscriptionFeature::query()
            ->where('subscription_id', $subscription->id)
            ->where('feature_slug', $featureSlug)
            ->whereNull('superseded_at')
            ->first();
    }

    public function asOf(Subscription $subscription, \DateTimeInterface $moment): Collection
    {
        return SubscriptionFeature::query()
            ->where('subscription_id', $subscription->id)
            ->where('added_at', '<=', $moment)
            ->where(function ($q) use ($moment) {
                $q->whereNull('superseded_at')->orWhere('superseded_at', '>', $moment);
            })
            ->get();
    }

    public function insert(array $data): SubscriptionFeature
    {
        return SubscriptionFeature::query()->create($data);
    }

    public function supersedeAll(Subscription $subscription, \DateTimeInterface $when): int
    {
        return SubscriptionFeature::query()
            ->where('subscription_id', $subscription->id)
            ->whereNull('superseded_at')
            ->update(['superseded_at' => $when]);
    }
}
