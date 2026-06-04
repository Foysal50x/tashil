<?php

declare(strict_types=1);

namespace App\Models;

use Foysal50x\Tashil\Contracts\Subscribable;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Traits\HasSubscriptions;
use Illuminate\Foundation\Auth\User as Authenticatable;

/**
 * The host's subscriber model.
 *
 * Two things make any Eloquent model a Tashil subscriber:
 *   1. `implements Subscribable` — every library type-hint accepts the
 *      Subscribable contract, NEVER Eloquent's Model. This is a hard
 *      requirement, not a suggestion.
 *   2. `use HasSubscriptions` — provides default implementations of the
 *      contract (subscriptions(), resolveSubscription(), getSubscriberKey(),
 *      getSubscriberType()) plus every convenience helper:
 *      subscribe(), useFeature(), hasFeature(), cancelSubscription(), etc.
 *
 * A Team, Organization, Workspace, or Tenant model works exactly the same
 * way — Tashil never assumes the subscriber is a "user".
 */
class User extends Authenticatable implements Subscribable
{
    use HasSubscriptions;

    /**
     * OPTIONAL. Override only when a subscriber can hold more than one live
     * subscription and you must choose which one is "the active one" that
     * every feature/lifecycle helper resolves against.
     *
     * The default (shown here for reference) returns the latest valid
     * subscription. A multi-workspace SaaS might instead scope to the
     * workspace the request is acting on:
     *
     *     return $this->subscriptions()->valid()
     *         ->where('package_id', $this->currentWorkspace->plan_id)
     *         ->first();
     *
     * Remove this method entirely if you only ever have one subscription per
     * subscriber — the trait's default already does the right thing.
     */
    public function resolveSubscription(): ?Subscription
    {
        return $this->subscriptions()->valid()->latest('id')->first();
    }
}
