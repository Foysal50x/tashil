<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Repositories;

use Foysal50x\Tashil\Contracts\SubscriptionEventRepositoryInterface;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

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

    public function maxSequence(int $subscriptionId): int
    {
        return (int) SubscriptionEvent::query()
            ->where('subscription_id', $subscriptionId)
            ->max('sequence_num');
    }

    public function paginateForSubscription(int $subscriptionId, int $perPage = 20, array $with = [], string $pageName = 'page'): LengthAwarePaginator
    {
        return $this->paginateHistory(
            $this->newHistoryQuery()->where('subscription_id', $subscriptionId),
            $perPage,
            $with,
            $pageName,
        );
    }

    public function paginateForSubscriber(string $subscriberType, int|string $subscriberId, int $perPage = 20, array $with = [], string $pageName = 'page'): LengthAwarePaginator
    {
        $subscriptionIds = Subscription::query()
            ->where('subscriber_type', $subscriberType)
            ->where('subscriber_id', $subscriberId)
            ->select('id');

        return $this->paginateHistory(
            $this->newHistoryQuery()->whereIn('subscription_id', $subscriptionIds),
            $perPage,
            $with,
            $pageName,
        );
    }

    public function paginateForPackage(int $packageId, int $perPage = 20, array $with = [], string $pageName = 'page'): LengthAwarePaginator
    {
        $subscriptionIds = Subscription::query()
            ->where('package_id', $packageId)
            ->select('id');

        return $this->paginateHistory(
            $this->newHistoryQuery()->whereIn('subscription_id', $subscriptionIds),
            $perPage,
            $with,
            $pageName,
        );
    }

    /**
     * Base history query: newest first, with sequence_num as a deterministic
     * tie-breaker for events that share an occurred_at instant.
     *
     * @return Builder<SubscriptionEvent>
     */
    private function newHistoryQuery(): Builder
    {
        return SubscriptionEvent::query()
            ->latest('occurred_at')
            ->orderByDesc('sequence_num');
    }

    /**
     * @param  Builder<SubscriptionEvent>  $query
     * @param  list<string>  $with
     * @return LengthAwarePaginator<SubscriptionEvent>
     */
    private function paginateHistory(Builder $query, int $perPage, array $with, string $pageName): LengthAwarePaginator
    {
        return $query->with($with)->paginate($perPage, ['*'], $pageName);
    }
}
