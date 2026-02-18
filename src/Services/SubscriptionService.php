<?php

namespace Foysal50x\Tashil\Services;

use Carbon\Carbon;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Events\SubscriptionCreated;
use Foysal50x\Tashil\Managers\DatabaseManager;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Database\Eloquent\Model;

class SubscriptionService
{
    public function __construct(
        protected DatabaseManager $db,
        protected SubscriptionRepositoryInterface $subscriptionRepo,
    ) {}

    /**
     * Subscribe a model to a package.
     */
    public function subscribe(Model $subscriber, Package $package, bool $withTrial = false): Subscription
    {
        return $this->db->connection()->transaction(function () use ($subscriber, $package, $withTrial) {
            $startsAt = Carbon::now();
            $trialEndsAt = null;
            $status = SubscriptionStatus::Active;

            // Handle trial
            if ($withTrial && $package->trial_days > 0) {
                $trialEndsAt = $startsAt->copy()->addDays($package->trial_days);
                $status = SubscriptionStatus::OnTrial;
            }

            // Calculate ends_at based on billing period
            $endsAt = $this->calculateEndDate($startsAt, $package);

            // If on trial, subscription doesn't "end" until trial is over (at minimum)
            if ($trialEndsAt && (! $endsAt || $trialEndsAt->greaterThan($endsAt))) {
                $endsAt = $trialEndsAt;
            }

            $subscription = $this->subscriptionRepo->create([
                'subscriber_type'  => get_class($subscriber),
                'subscriber_id'    => $subscriber->getKey(),
                'package_id'       => $package->id,
                'status'           => $status,
                'starts_at'        => $startsAt,
                'ends_at'          => $endsAt,
                'trial_ends_at'    => $trialEndsAt,
                'auto_renew'       => true,
            ]);

            // Sync features from package → subscription items
            $this->subscriptionRepo->syncFeatureItems($subscription, $package);

            SubscriptionCreated::dispatch($subscription);

            return $subscription;
        });
    }

    /**
     * Cancel a subscription.
     */
    public function cancel(Subscription $subscription, bool $immediate = false, ?string $reason = null): Subscription
    {
        $data = [
            'cancelled_at'        => now(),
            'cancellation_reason' => $reason,
        ];

        if ($immediate) {
            $data['status'] = SubscriptionStatus::Cancelled;
            $data['ends_at'] = now();
        } else {
            // Grace period: mark cancelled but keep active until ends_at
            $data['status'] = SubscriptionStatus::Cancelled;
            $data['auto_renew'] = false;
        }

        return $this->subscriptionRepo->update($subscription, $data);
    }

    /**
     * Resume a cancelled subscription (before it expires).
     */
    public function resume(Subscription $subscription): Subscription
    {
        if ($subscription->status !== SubscriptionStatus::Cancelled) {
            return $subscription;
        }

        // Can only resume if ends_at is in the future
        if ($subscription->ends_at && $subscription->ends_at->isPast()) {
            return $subscription;
        }

        return $this->subscriptionRepo->update($subscription, [
            'status'              => SubscriptionStatus::Active,
            'cancelled_at'        => null,
            'cancellation_reason' => null,
            'auto_renew'          => true,
        ]);
    }

    /**
     * Switch subscription to a different package.
     * Creates a new subscription and cancels the old one.
     */
    public function switchPlan(Subscription $subscription, Package $newPackage): Subscription
    {
        return $this->db->connection()->transaction(function () use ($subscription, $newPackage) {
            // Cancel old subscription immediately
            $this->cancel($subscription, immediate: true, reason: 'Plan switch');

            // Create new subscription for the same subscriber
            return $this->subscribe(
                $subscription->subscriber,
                $newPackage
            );
        });
    }

    // ── Internal ────────────────────────────────────────────────

    protected function calculateEndDate(Carbon $start, Package $package): ?Carbon
    {
        if ($package->billing_period === Period::Lifetime) {
            return null; // never expires
        }

        $interval = $package->billing_interval;

        return match ($package->billing_period) {
            Period::Day   => $start->copy()->addDays($interval),
            Period::Week  => $start->copy()->addWeeks($interval),
            Period::Month => $start->copy()->addMonths($interval),
            Period::Year  => $start->copy()->addYears($interval),
            default       => $start->copy()->addMonth(),
        };
    }
}
