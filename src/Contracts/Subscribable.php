<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Contracts;

use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Contract for any model that can hold tahsil subscriptions.
 *
 * Implementers must be Eloquent models. The HasSubscriptions trait provides
 * default implementations for all four methods; hosts add `implements
 * Subscribable` so library type-hints stay sharp and IDE/static analysis
 * picks up the surface uniformly.
 *
 * Multi-subscription hosts override resolveSubscription() to choose which
 * subscription represents "the active one" for gating, middleware, and
 * the request-scoped cache on HasSubscriptions::loadSubscription().
 */
interface Subscribable
{
    /**
     * Polymorphic relation to all subscriptions ever held.
     */
    public function subscriptions(): MorphMany;

    /**
     * Pick the subscription used for gating + feature checks.
     *
     * Default (HasSubscriptions trait): the latest valid subscription.
     * Override to prefer a specific package, tenant scope, etc.
     */
    public function resolveSubscription(): ?Subscription;

    /**
     * Primary key of the subscriber. Used in repository lookups.
     */
    public function getSubscriberKey(): int|string;

    /**
     * Polymorphic morph type of the subscriber.
     */
    public function getSubscriberType(): string;
}
