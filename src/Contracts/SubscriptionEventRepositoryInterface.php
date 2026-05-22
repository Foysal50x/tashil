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
}
