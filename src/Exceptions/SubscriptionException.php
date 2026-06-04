<?php

namespace Foysal50x\Tashil\Exceptions;

use Foysal50x\Tashil\Contracts\Subscribable;
use Foysal50x\Tashil\Models\Subscription;
use RuntimeException;

/**
 * Domain errors raised by SubscriptionService while moving a subscription
 * through its lifecycle (subscribe, switch, change plan).
 *
 * Each failure mode has a named constructor so call sites read as intent
 * ("subscriber already has a live subscription") rather than a hand-built
 * string, and so hosts can catch a single type instead of matching on
 * message text. Extends RuntimeException to stay backward compatible with
 * existing `catch (RuntimeException)` handlers.
 */
class SubscriptionException extends RuntimeException
{
    /**
     * subscribe() refuses to create a second live subscription for the same
     * subscriber — duplicates come from double-submits/retries. Moving plans
     * goes through changePlan()/switchPlan(); a fresh subscribe() requires the
     * previous subscription to be terminal first.
     */
    public static function alreadySubscribed(Subscribable $subscriber): self
    {
        return new self(sprintf(
            'Subscriber (%s #%s) already has a live subscription. Use changePlan() or switchPlan() to move plans, or cancel the current one first.',
            $subscriber->getSubscriberType(),
            (string) $subscriber->getSubscriberKey(),
        ));
    }

    /**
     * switchPlan() needs the subscriber to implement Subscribable so a new
     * subscription can be provisioned for it. A polymorphic relation that no
     * longer resolves to a Subscribable (stale type, deleted model) lands here.
     */
    public static function subscriberNotSubscribable(Subscription $subscription): self
    {
        return new self(sprintf(
            'Subscription %d cannot be switched: its subscriber (%s) does not implement %s.',
            $subscription->id,
            $subscription->subscriber_type,
            Subscribable::class,
        ));
    }

    /**
     * Proration math is only meaningful within a single currency. When the old
     * and new packages price in different currencies there is no defined
     * exchange, so the upgrade is rejected rather than billing a wrong amount.
     */
    public static function cannotProrateAcrossCurrencies(Subscription $subscription, string $oldCurrency, string $newCurrency): self
    {
        return new self(sprintf(
            'Cannot prorate plan change across currencies (%s → %s) for subscription %d; cancel and resubscribe instead.',
            $oldCurrency,
            $newCurrency,
            $subscription->id,
        ));
    }
}
