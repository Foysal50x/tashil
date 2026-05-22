<?php

namespace Foysal50x\Tashil\Services;

use Carbon\Carbon;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Events\PendingChangeApplied;
use Foysal50x\Tashil\Events\PendingChangeScheduled;
use Foysal50x\Tashil\Events\SubscriptionCancelled;
use Foysal50x\Tashil\Events\SubscriptionCreated;
use Foysal50x\Tashil\Events\SubscriptionExpired;
use Foysal50x\Tashil\Events\SubscriptionPaused;
use Foysal50x\Tashil\Events\SubscriptionResumed;
use Foysal50x\Tashil\Events\SubscriptionSwitched;
use Foysal50x\Tashil\Events\SubscriptionUnpaused;
use Foysal50x\Tashil\Events\TrialConverted;
use Foysal50x\Tashil\Events\TrialExpired;
use Foysal50x\Tashil\Managers\DatabaseManager;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

class SubscriptionService
{
    public function __construct(
        protected DatabaseManager $db,
        protected SubscriptionRepositoryInterface $subscriptionRepo,
        protected EventStore $eventStore,
    ) {}

    // ── Subscribe ───────────────────────────────────────────────

    public function subscribe(Model $subscriber, Package $package, bool $withTrial = false): Subscription
    {
        return $this->db->connection()->transaction(function () use ($subscriber, $package, $withTrial) {
            $startsAt = Carbon::now();
            $trialEndsAt = null;
            $trialStartedAt = null;
            $status = SubscriptionStatus::Active;

            if ($withTrial && $package->trial_days > 0) {
                $trialStartedAt = $startsAt->copy();
                $trialEndsAt = $startsAt->copy()->addDays($package->trial_days);
                $status = SubscriptionStatus::OnTrial;
            }

            $periodEnd = $this->calculatePeriodEnd($startsAt, $package);

            // ends_at marks the lifetime cutoff; for non-lifetime it tracks
            // the current period (extended on renewal) or the trial end,
            // whichever is later.
            $endsAt = $periodEnd;
            if ($trialEndsAt && (! $endsAt || $trialEndsAt->greaterThan($endsAt))) {
                $endsAt = $trialEndsAt;
            }

            $subscription = $this->subscriptionRepo->create([
                'subscriber_type'      => $subscriber->getMorphClass(),
                'subscriber_id'        => $subscriber->getKey(),
                'package_id'           => $package->id,
                'status'               => $status,
                'starts_at'            => $startsAt,
                'ends_at'              => $endsAt,
                'current_period_start' => $startsAt,
                'current_period_end'   => $periodEnd,
                'trial_started_at'     => $trialStartedAt,
                'trial_ends_at'        => $trialEndsAt,
                'auto_renew'           => true,
            ]);

            $this->subscriptionRepo->syncFeatures($subscription, $package);

            $this->eventStore->append($subscription, 'subscription.created', [
                'package_id'   => $package->id,
                'package_slug' => $package->slug,
                'with_trial'   => $withTrial && $trialEndsAt !== null,
                'starts_at'    => (string) $startsAt,
                'ends_at'      => $endsAt ? (string) $endsAt : null,
            ]);

            $this->dispatchAfterCommit(fn () => SubscriptionCreated::dispatch($subscription));

            return $subscription;
        });
    }

    // ── Cancel ──────────────────────────────────────────────────

    /**
     * Cancel a subscription.
     *
     * Immediate cancel: status → Cancelled, access revoked now.
     * Grace cancel:     status → PendingCancellation, access retained
     *                   until ends_at; the expire-subscriptions job will
     *                   later promote it to Expired.
     */
    public function cancel(Subscription $subscription, bool $immediate = false, ?string $reason = null): Subscription
    {
        return $this->db->connection()->transaction(function () use ($subscription, $immediate, $reason) {
            $now = now();

            if ($immediate) {
                $data = [
                    'status'                    => SubscriptionStatus::Cancelled,
                    'cancelled_at'              => $now,
                    'cancellation_effective_at' => $now,
                    'cancellation_reason'       => $reason,
                    'ends_at'                   => $now,
                    'auto_renew'                => false,
                ];
            } else {
                $effectiveAt = $subscription->ends_at ?? $subscription->current_period_end ?? $now;
                $data = [
                    'status'                    => SubscriptionStatus::PendingCancellation,
                    'cancelled_at'              => $now,
                    'cancellation_effective_at' => $effectiveAt,
                    'cancellation_reason'       => $reason,
                    'auto_renew'                => false,
                ];
            }

            $subscription = $this->subscriptionRepo->update($subscription, $data);

            $this->eventStore->append($subscription, 'subscription.cancelled', [
                'immediate' => $immediate,
                'reason'    => $reason,
            ]);

            $this->dispatchAfterCommit(fn () => SubscriptionCancelled::dispatch($subscription, $immediate, $reason));

            return $subscription;
        });
    }

    // ── Resume ──────────────────────────────────────────────────

    /**
     * Resume a pending-cancellation subscription before it expires.
     */
    public function resume(Subscription $subscription): Subscription
    {
        if (! $subscription->isPendingCancellation()) {
            return $subscription;
        }

        if ($subscription->ends_at && $subscription->ends_at->isPast()) {
            return $subscription;
        }

        return $this->db->connection()->transaction(function () use ($subscription) {
            $subscription = $this->subscriptionRepo->update($subscription, [
                'status'                    => SubscriptionStatus::Active,
                'cancelled_at'              => null,
                'cancellation_effective_at' => null,
                'cancellation_reason'       => null,
                'auto_renew'                => true,
            ]);

            $this->eventStore->append($subscription, 'subscription.resumed');
            $this->dispatchAfterCommit(fn () => SubscriptionResumed::dispatch($subscription));

            return $subscription;
        });
    }

    // ── Expire ──────────────────────────────────────────────────

    /**
     * Promote a subscription to Expired. Called by the scheduled
     * expire-subscriptions job after ends_at / cancellation_effective_at.
     */
    public function expire(Subscription $subscription): Subscription
    {
        if ($subscription->status === SubscriptionStatus::Expired) {
            return $subscription;
        }

        return $this->db->connection()->transaction(function () use ($subscription) {
            $subscription = $this->subscriptionRepo->update($subscription, [
                'status' => SubscriptionStatus::Expired,
            ]);

            $this->eventStore->append($subscription, 'subscription.expired');
            $this->dispatchAfterCommit(fn () => SubscriptionExpired::dispatch($subscription));

            return $subscription;
        });
    }

    // ── Trial transitions ───────────────────────────────────────

    public function convertTrial(Subscription $subscription): Subscription
    {
        if (! $subscription->isOnTrial()) {
            return $subscription;
        }

        return $this->db->connection()->transaction(function () use ($subscription) {
            $now = now();

            $subscription = $this->subscriptionRepo->update($subscription, [
                'status'             => SubscriptionStatus::Active,
                'trial_converted_at' => $now,
            ]);

            $this->eventStore->append($subscription, 'trial.converted');
            $this->dispatchAfterCommit(fn () => TrialConverted::dispatch($subscription));

            return $subscription;
        });
    }

    public function expireTrial(Subscription $subscription): Subscription
    {
        if ($subscription->status !== SubscriptionStatus::OnTrial) {
            return $subscription;
        }

        return $this->db->connection()->transaction(function () use ($subscription) {
            $now = now();

            $subscription = $this->subscriptionRepo->update($subscription, [
                'status'           => SubscriptionStatus::Expired,
                'trial_expired_at' => $now,
            ]);

            $this->eventStore->append($subscription, 'trial.expired');
            $this->dispatchAfterCommit(fn () => TrialExpired::dispatch($subscription));

            return $subscription;
        });
    }

    // ── Pause / Unpause ─────────────────────────────────────────

    public function pause(Subscription $subscription): Subscription
    {
        if ($subscription->isPaused()) {
            return $subscription;
        }

        return $this->db->connection()->transaction(function () use ($subscription) {
            $subscription = $this->subscriptionRepo->update($subscription, [
                'status' => SubscriptionStatus::Paused,
            ]);

            $this->eventStore->append($subscription, 'subscription.paused');
            $this->dispatchAfterCommit(fn () => SubscriptionPaused::dispatch($subscription));

            return $subscription;
        });
    }

    public function unpause(Subscription $subscription): Subscription
    {
        if (! $subscription->isPaused()) {
            return $subscription;
        }

        return $this->db->connection()->transaction(function () use ($subscription) {
            $subscription = $this->subscriptionRepo->update($subscription, [
                'status' => SubscriptionStatus::Active,
            ]);

            $this->eventStore->append($subscription, 'subscription.unpaused');
            $this->dispatchAfterCommit(fn () => SubscriptionUnpaused::dispatch($subscription));

            return $subscription;
        });
    }

    // ── Switch plan ─────────────────────────────────────────────

    /**
     * Switch a subscription to a different package — cancels the old
     * subscription immediately and creates a new one. Trial state is
     * carried forward when both old and new packages offer a trial and
     * the old subscription is still mid-trial.
     */
    public function switchPlan(Subscription $subscription, Package $newPackage): Subscription
    {
        return $this->db->connection()->transaction(function () use ($subscription, $newPackage) {
            $oldPackage = $subscription->package;
            $carryTrial = $subscription->isOnTrial() && $newPackage->trial_days > 0;

            $this->cancel($subscription, immediate: true, reason: 'Plan switch');

            $newSubscription = $this->subscribe(
                $subscription->subscriber,
                $newPackage,
                withTrial: $carryTrial,
            );

            // Cross-link via event payload — the new subscription's first
            // event is its 'created' event; we attach a 'switched' marker
            // to the old subscription that records the new id.
            $this->eventStore->append($subscription, 'subscription.switched', [
                'new_subscription_id' => $newSubscription->id,
                'new_package_id'      => $newPackage->id,
                'new_package_slug'    => $newPackage->slug,
            ]);

            $this->dispatchAfterCommit(fn () => SubscriptionSwitched::dispatch(
                $subscription,
                $newSubscription,
                $oldPackage,
                $newPackage,
            ));

            return $newSubscription;
        });
    }

    // ── Scheduled change ────────────────────────────────────────

    /**
     * Queue a package change to take effect when the current period ends.
     * Typical usage: queue a downgrade so it applies after the period the
     * customer has already paid for.
     */
    public function scheduleDowngrade(Subscription $subscription, Package $targetPackage): Subscription
    {
        return $this->db->connection()->transaction(function () use ($subscription, $targetPackage) {
            $effectiveAt = $subscription->current_period_end
                ?? $subscription->ends_at
                ?? now();

            $subscription = $this->subscriptionRepo->update($subscription, [
                'pending_package_id' => $targetPackage->id,
                'pending_change_at'  => $effectiveAt,
            ]);

            $this->eventStore->append($subscription, 'subscription.pending_change_scheduled', [
                'target_package_id'   => $targetPackage->id,
                'target_package_slug' => $targetPackage->slug,
                'effective_at'        => (string) $effectiveAt,
            ]);

            $this->dispatchAfterCommit(fn () => PendingChangeScheduled::dispatch(
                $subscription,
                $targetPackage,
                $effectiveAt,
            ));

            return $subscription;
        });
    }

    public function cancelPendingChange(Subscription $subscription): Subscription
    {
        if (! $subscription->hasPendingChange()) {
            return $subscription;
        }

        return $this->db->connection()->transaction(function () use ($subscription) {
            $subscription = $this->subscriptionRepo->update($subscription, [
                'pending_package_id' => null,
                'pending_change_at'  => null,
            ]);

            $this->eventStore->append($subscription, 'subscription.pending_change_cancelled');

            return $subscription;
        });
    }

    /**
     * Apply a previously scheduled package change. Invoked by the
     * apply-pending-changes job once pending_change_at has elapsed.
     */
    public function applyPendingChange(Subscription $subscription): Subscription
    {
        if (! $subscription->hasPendingChange()) {
            return $subscription;
        }

        $targetPackage = $subscription->pendingPackage;
        if (! $targetPackage) {
            return $subscription;
        }

        $newSubscription = $this->switchPlan($subscription, $targetPackage);

        $this->dispatchAfterCommit(fn () => PendingChangeApplied::dispatch($subscription, $newSubscription));

        return $newSubscription;
    }

    // ── Renewal advancement ─────────────────────────────────────

    /**
     * Advance current_period_end by one billing period. Called by
     * InvoiceObserver when an invoice is marked paid.
     */
    public function advancePeriod(Subscription $subscription): Subscription
    {
        return $this->db->connection()->transaction(function () use ($subscription) {
            $package = $subscription->package;
            $anchor = $subscription->current_period_end ?? now();
            $newEnd = $this->calculatePeriodEnd(Carbon::instance($anchor), $package);

            $subscription = $this->subscriptionRepo->update($subscription, [
                'current_period_start' => $anchor,
                'current_period_end'   => $newEnd,
                'ends_at'              => $newEnd,
            ]);

            $this->eventStore->append($subscription, 'subscription.renewed', [
                'new_period_end' => $newEnd ? (string) $newEnd : null,
            ]);

            return $subscription;
        });
    }

    // ── Internal ────────────────────────────────────────────────

    protected function calculatePeriodEnd(Carbon $start, Package $package): ?Carbon
    {
        if ($package->billing_period === Period::Lifetime) {
            return null;
        }

        $interval = max(1, (int) $package->billing_interval);

        return match ($package->billing_period) {
            Period::Day   => $start->copy()->addDays($interval),
            Period::Week  => $start->copy()->addWeeks($interval),
            Period::Month => $start->copy()->addMonths($interval),
            Period::Year  => $start->copy()->addYears($interval),
            default       => $start->copy()->addMonth(),
        };
    }

    protected function dispatchAfterCommit(\Closure $dispatcher): void
    {
        if (Config::get('tashil.events.async', true)) {
            DB::afterCommit($dispatcher);

            return;
        }

        $dispatcher();
    }
}
