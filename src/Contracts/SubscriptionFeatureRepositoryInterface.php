<?php

namespace Foysal50x\Tashil\Contracts;

use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionFeature;
use Illuminate\Database\Eloquent\Collection;

interface SubscriptionFeatureRepositoryInterface
{
    public function current(Subscription $subscription): Collection;

    public function findCurrentBySlug(Subscription $subscription, string $featureSlug): ?SubscriptionFeature;

    public function asOf(Subscription $subscription, \DateTimeInterface $moment): Collection;

    public function insert(array $data): SubscriptionFeature;

    public function supersedeAll(Subscription $subscription, \DateTimeInterface $when): int;
}
