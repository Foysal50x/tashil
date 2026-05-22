<?php

namespace Foysal50x\Tashil\Repositories;

use Foysal50x\Tashil\Contracts\SubscriptionEventRepositoryInterface;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionEvent;
use Illuminate\Database\Eloquent\Collection;

class EloquentSubscriptionEventRepository implements SubscriptionEventRepositoryInterface
{
    public function findByIdempotencyKey(int $subscriptionId, string $key): ?SubscriptionEvent
    {
        return SubscriptionEvent::query()
            ->where('subscription_id', $subscriptionId)
            ->where('idempotency_key', $key)
            ->first();
    }

    public function insert(array $data): SubscriptionEvent
    {
        return SubscriptionEvent::query()->create($data);
    }

    public function listForSubscription(Subscription $subscription, ?string $eventType = null, ?int $limit = null): Collection
    {
        $query = SubscriptionEvent::query()
            ->where('subscription_id', $subscription->id)
            ->orderBy('sequence_num');

        if ($eventType !== null) {
            $query->where('event_type', $eventType);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    public function listUpTo(Subscription $subscription, \DateTimeInterface $moment): Collection
    {
        return SubscriptionEvent::query()
            ->where('subscription_id', $subscription->id)
            ->where('occurred_at', '<=', $moment)
            ->orderBy('sequence_num')
            ->get();
    }
}
