<?php

namespace Foysal50x\Tashil\Contracts;

use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionEvent;
use Illuminate\Database\Eloquent\Collection;

interface SubscriptionEventRepositoryInterface
{
    public function findByIdempotencyKey(int $subscriptionId, string $key): ?SubscriptionEvent;

    public function insert(array $data): SubscriptionEvent;

    public function listForSubscription(Subscription $subscription, ?string $eventType = null, ?int $limit = null): Collection;

    public function listUpTo(Subscription $subscription, \DateTimeInterface $moment): Collection;

    /**
     * Return the largest sequence_num currently persisted for the
     * subscription, or 0 if no events exist. Used by the EventStore's
     * retry path to recover when the cached last_event_seq has drifted
     * from reality (e.g. after an out-of-band raw insert).
     */
    public function maxSequence(int $subscriptionId): int;
}
