<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Contracts;

use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

interface SubscriptionEventRepositoryInterface
{
    public function findByIdempotencyKey(int $subscriptionId, string $key): ?SubscriptionEvent;

    public function insert(array $data): SubscriptionEvent;

    public function listForSubscription(Subscription $subscription, ?string $eventType = null, ?int $limit = null): Collection;

    public function listUpTo(Subscription $subscription, \DateTimeInterface $moment): Collection;

    /**
     * Paginate the immutable event log for a single subscription, newest first.
     *
     * @param  list<string>  $with  relations to eager-load (avoids N+1 in timelines)
     * @return LengthAwarePaginator<SubscriptionEvent>
     */
    public function paginateForSubscription(int $subscriptionId, int $perPage = 20, array $with = [], string $pageName = 'page'): LengthAwarePaginator;

    /**
     * Paginate the event log across every subscription held by a subscriber,
     * newest first.
     *
     * @param  list<string>  $with  relations to eager-load
     * @return LengthAwarePaginator<SubscriptionEvent>
     */
    public function paginateForSubscriber(string $subscriberType, int|string $subscriberId, int $perPage = 20, array $with = [], string $pageName = 'page'): LengthAwarePaginator;

    /**
     * Paginate the event log across every subscription on a plan, newest first.
     *
     * @param  list<string>  $with  relations to eager-load
     * @return LengthAwarePaginator<SubscriptionEvent>
     */
    public function paginateForPackage(int $packageId, int $perPage = 20, array $with = [], string $pageName = 'page'): LengthAwarePaginator;

    /**
     * Return the largest sequence_num currently persisted for the
     * subscription, or 0 if no events exist. Used by the EventStore's
     * retry path to recover when the cached last_event_seq has drifted
     * from reality (e.g. after an out-of-band raw insert).
     */
    public function maxSequence(int $subscriptionId): int;
}
