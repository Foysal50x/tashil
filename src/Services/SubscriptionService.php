<?php

namespace Foysal50x\Tashil\Services;

use Carbon\Carbon;
use Foysal50x\Tashil\Contracts\FeatureUsageRepositoryInterface;
use Foysal50x\Tashil\Contracts\Subscribable;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Enums\InvoiceKind;
use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Events\InvoiceOverdue;
use Foysal50x\Tashil\Events\PendingChangeApplied;
use Foysal50x\Tashil\Events\PendingChangeScheduled;
use Foysal50x\Tashil\Events\SubscriptionActivated;
use Foysal50x\Tashil\Events\SubscriptionCancelled;
use Foysal50x\Tashil\Events\SubscriptionCreated;
use Foysal50x\Tashil\Events\SubscriptionExpired;
use Foysal50x\Tashil\Events\SubscriptionPastDue;
use Foysal50x\Tashil\Events\SubscriptionPaused;
use Foysal50x\Tashil\Events\SubscriptionPlanChanged;
use Foysal50x\Tashil\Events\SubscriptionReactivated;
use Foysal50x\Tashil\Events\SubscriptionResumed;
use Foysal50x\Tashil\Events\SubscriptionSuspended;
use Foysal50x\Tashil\Events\SubscriptionSwitched;
use Foysal50x\Tashil\Events\SubscriptionUnpaused;
use Foysal50x\Tashil\Events\TrialConverted;
use Foysal50x\Tashil\Events\TrialExpired;
use Foysal50x\Tashil\Exceptions\SubscriptionException;
use Foysal50x\Tashil\Managers\DatabaseManager;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Traits\DispatchesEventsAfterCommit;
use Illuminate\Support\Facades\Config;

class SubscriptionService
{
    use DispatchesEventsAfterCommit;

    public function __construct(
        protected DatabaseManager $db,
        protected SubscriptionRepositoryInterface $subscriptionRepo,
        protected EventStore $eventStore,
        protected BillingService $billing,
        protected FeatureUsageRepositoryInterface $usageRepo,
    ) {}

    /**
     * Subscribe a subscriber to a package.
     *
     * Activation model — driven by the package's own `requires_payment` flag
     * (seeded at creation from config `tashil.billing.activate_on_payment`;
     * the package is authoritative thereafter):
     *  - Trial plan          → status OnTrial, access granted, no invoice.
     *                          The first invoice is issued at convertTrial().
     *  - Priced + requires
     *    payment (default)   → status Pending, NO access. An `initial`
     *                          invoice is issued; activate() runs when it is
     *                          paid (InvoiceObserver). Period is anchored at
     *                          activation, not here.
     *  - Free (price 0) /
     *    requires_payment off → status Active immediately, period anchored now.
     */
    public function subscribe(Subscribable $subscriber, Package $package, bool $withTrial = false): Subscription
    {
        // Guard against accidental duplicate subscriptions (double-submit,
        // retries). Moving plans goes through changePlan()/switchPlan(); a
        // new subscribe() requires the previous one to be terminal first.
        if ($this->subscriptionRepo->hasLiveSubscription($subscriber)) {
            throw SubscriptionException::alreadySubscribed($subscriber);
        }

        return $this->provision($subscriber, $package, $withTrial, gatePayment: true);
    }

    /**
     * Core subscription provisioning.
     *
     * @param  bool  $gatePayment  When true, a priced plan is gated behind a
     *                             paid initial invoice (the public subscribe() path). When false, the
     *                             subscription is provisioned active immediately and no initial invoice
     *                             is issued — used by switchPlan(), where an already-active customer is
     *                             moving to a new plan and must not lose access or be billed twice
     *                             (the change is settled by proration or the next renewal).
     */
    protected function provision(
        Subscribable $subscriber,
        Package $package,
        bool $withTrial,
        bool $gatePayment,
    ): Subscription {
        return $this->db->connection()->transaction(function () use ($subscriber, $package, $withTrial, $gatePayment) {
            $startsAt = Carbon::now();
            $isTrial = $withTrial && $package->trial_days > 0;
            $requiresPayment = $gatePayment && $this->requiresPayment($package);

            $trialStartedAt = null;
            $trialEndsAt = null;
            $currentPeriodStart = null;
            $periodEnd = null;
            $endsAt = null;
            $activatedAt = null;

            if ($isTrial) {
                $trialStartedAt = $startsAt->copy();
                $trialEndsAt = $startsAt->copy()->addDays($package->trial_days);
                $status = SubscriptionStatus::OnTrial;

                $currentPeriodStart = $startsAt;
                $periodEnd = $this->calculatePeriodEnd($startsAt, $package);

                // ends_at tracks the current period or the trial end,
                // whichever is later, so the sub stays valid until the trial
                // actually expires.
                $endsAt = $periodEnd;
                if ($trialEndsAt && (! $endsAt || $trialEndsAt->greaterThan($endsAt))) {
                    $endsAt = $trialEndsAt;
                }
            } elseif ($requiresPayment) {
                // Strict activate-on-payment: no period, no access until the
                // initial invoice is paid.
                $status = SubscriptionStatus::Pending;
            } else {
                // Free / offline / legacy-mode plan — activate immediately.
                $status = SubscriptionStatus::Active;
                $currentPeriodStart = $startsAt;
                $periodEnd = $this->calculatePeriodEnd($startsAt, $package);
                $endsAt = $periodEnd;
                $activatedAt = $startsAt;
            }

            // Lifetime packages have no renewal cycle. Setting auto_renew
            // false is defense-in-depth: dueForRenewal already filters by
            // whereNotNull(current_period_end), but a future manual write
            // that populates current_period_end shouldn't suddenly cause
            // a lifetime subscription to start renewing.
            $autoRenew = $package->billing_period !== Period::Lifetime;

            $subscription = $this->subscriptionRepo->create([
                'subscriber_type'      => $subscriber->getSubscriberType(),
                'subscriber_id'        => $subscriber->getSubscriberKey(),
                'package_id'           => $package->id,
                'status'               => $status,
                'starts_at'            => $status === SubscriptionStatus::Pending ? null : $startsAt,
                'ends_at'              => $endsAt,
                'current_period_start' => $currentPeriodStart,
                'current_period_end'   => $periodEnd,
                'trial_started_at'     => $trialStartedAt,
                'trial_ends_at'        => $trialEndsAt,
                'activated_at'         => $activatedAt,
                'auto_renew'           => $autoRenew,
            ]);

            $this->subscriptionRepo->syncFeatures($subscription, $package);

            $this->eventStore->append($subscription, 'subscription.created', [
                'package_id'       => $package->id,
                'package_slug'     => $package->slug,
                'with_trial'       => $isTrial,
                'requires_payment' => $requiresPayment && ! $isTrial,
                'status'           => $status->value,
                'starts_at'        => $subscription->starts_at ? (string) $subscription->starts_at : null,
                'ends_at'          => $endsAt ? (string) $endsAt : null,
            ]);

            // Priced, non-trial plans bill up front: the subscription stays
            // Pending until this invoice is paid.
            if ($requiresPayment && ! $isTrial) {
                $this->billing->issueInitialInvoice($subscription);
            }

            $this->dispatchAfterCommit(fn () => SubscriptionCreated::dispatch($subscription));

            // Free / offline plans are active the moment they're created.
            if ($status === SubscriptionStatus::Active) {
                $this->dispatchAfterCommit(fn () => SubscriptionActivated::dispatch($subscription, null));
            }

            return $subscription;
        });
    }

    /**
     * Activate a pending subscription — the transition that grants access
     * under the activate-on-payment model. Invoked by the InvoiceObserver
     * when the `initial` invoice is paid. Anchors the billing period and the
     * usage counters to the activation moment (the invoice's paid_at when
     * available, else now).
     */
    public function activate(Subscription $subscription, ?Invoice $invoice = null): Subscription
    {
        if ($subscription->status !== SubscriptionStatus::Pending) {
            return $subscription;
        }

        return $this->db->connection()->transaction(function () use ($subscription, $invoice) {
            $package = $subscription->package;
            $now = $invoice && $invoice->paid_at
                ? Carbon::instance($invoice->paid_at)
                : now();

            $periodEnd = $this->calculatePeriodEnd($now->copy(), $package);

            $subscription = $this->subscriptionRepo->update($subscription, [
                'status'               => SubscriptionStatus::Active,
                'activated_at'         => $now,
                'starts_at'            => $subscription->starts_at ?? $now,
                'current_period_start' => $now,
                'current_period_end'   => $periodEnd,
                'ends_at'              => $periodEnd,
            ]);

            // The counters were created at subscribe time with a provisional
            // window. Re-anchor them so the first quota period begins when
            // access actually begins.
            $this->usageRepo->reanchorPeriods($subscription, $now);

            $this->eventStore->append($subscription, 'subscription.activated', [
                'invoice_id' => $invoice?->id,
            ]);

            $this->dispatchAfterCommit(fn () => SubscriptionActivated::dispatch($subscription, $invoice));

            return $subscription;
        });
    }

    /**
     * Bring a lapsed subscription (past_due / suspended / expired) back to
     * Active — typically because the host collected a previously failed
     * payment. Clears dunning state and re-anchors the period. No-op for any
     * other status (so paying an invoice on an active/cancelled sub never
     * "reactivates" it through this path).
     */
    public function reactivate(Subscription $subscription, ?Invoice $invoice = null): Subscription
    {
        $reactivatable = [
            SubscriptionStatus::PastDue,
            SubscriptionStatus::Suspended,
            SubscriptionStatus::Expired,
        ];

        if (! in_array($subscription->status, $reactivatable, true)) {
            return $subscription;
        }

        return $this->db->connection()->transaction(function () use ($subscription, $invoice) {
            $package = $subscription->package;
            $now = $invoice && $invoice->paid_at
                ? Carbon::instance($invoice->paid_at)
                : now();

            // If the existing period is still in the future, the customer is
            // already paid through it — just restore access, keep the window.
            // Otherwise (the usual lapse) start a fresh period from recovery.
            if ($subscription->current_period_end && $subscription->current_period_end->isFuture()) {
                $periodStart = $subscription->current_period_start
                    ? Carbon::instance($subscription->current_period_start)
                    : $now;
                $periodEnd = Carbon::instance($subscription->current_period_end);
            } else {
                $periodStart = $now;
                $periodEnd = $this->calculatePeriodEnd($now->copy(), $package);
            }

            $subscription = $this->subscriptionRepo->update($subscription, [
                'status'                    => SubscriptionStatus::Active,
                'current_period_start'      => $periodStart,
                'current_period_end'        => $periodEnd,
                'ends_at'                   => $periodEnd,
                'dunning_attempts'          => 0,
                'last_dunning_at'           => null,
                'suspended_at'              => null,
                'cancelled_at'              => null,
                'cancellation_effective_at' => null,
                'cancellation_reason'       => null,
                'auto_renew'                => $package->billing_period !== Period::Lifetime,
            ]);

            $this->eventStore->append($subscription, 'subscription.reactivated', [
                'invoice_id' => $invoice?->id,
            ]);

            $this->dispatchAfterCommit(fn () => SubscriptionReactivated::dispatch($subscription, $invoice));

            return $subscription;
        });
    }

    /**
     * Move a subscription into (or further along) the dunning cycle because
     * a renewal invoice went unpaid past its due date. Records the current
     * attempt number and fires SubscriptionPastDue + InvoiceOverdue so the
     * host can re-attempt the charge. Access is governed by
     * `dunning.keep_access_while_past_due`.
     */
    public function markPastDue(Subscription $subscription, Invoice $invoice, int $attempt): Subscription
    {
        return $this->db->connection()->transaction(function () use ($subscription, $invoice, $attempt) {
            $now = now();

            $subscription = $this->subscriptionRepo->update($subscription, [
                'status'           => SubscriptionStatus::PastDue,
                'dunning_attempts' => $attempt,
                'last_dunning_at'  => $now,
            ]);

            $invoice->forceFill([
                'attempts'        => $attempt,
                'last_attempt_at' => $now,
            ])->save();

            $this->eventStore->append($subscription, 'subscription.past_due', [
                'invoice_id' => $invoice->id,
                'attempt'    => $attempt,
            ]);

            $this->dispatchAfterCommit(fn () => SubscriptionPastDue::dispatch($subscription, $invoice, $attempt));
            $this->dispatchAfterCommit(fn () => InvoiceOverdue::dispatch($invoice));

            return $subscription;
        });
    }

    /**
     * Suspend a subscription once dunning retries are exhausted — access is
     * cut. The subscription can still be recovered by collecting payment
     * (reactivate()), or expired by the dunning job after the suspend grace.
     */
    public function suspend(Subscription $subscription): Subscription
    {
        if ($subscription->status === SubscriptionStatus::Suspended) {
            return $subscription;
        }

        return $this->db->connection()->transaction(function () use ($subscription) {
            $subscription = $this->subscriptionRepo->update($subscription, [
                'status'       => SubscriptionStatus::Suspended,
                'suspended_at' => now(),
            ]);

            $this->eventStore->append($subscription, 'subscription.suspended');
            $this->dispatchAfterCommit(fn () => SubscriptionSuspended::dispatch($subscription));

            return $subscription;
        });
    }

    /**
     * Whether subscribing to this package should gate access behind a paid
     * initial invoice. The package is authoritative: its `requires_payment`
     * flag is the source of truth at runtime (seeded at creation from
     * `tashil.billing.activate_on_payment` when the caller didn't set it —
     * see Package::booted()). False when the package opts out (free/offline)
     * or the price is 0. We deliberately do NOT re-read the global config
     * here: a package explicitly marked requires_payment=true must keep
     * collecting payment even if the install-wide default is later flipped.
     */
    protected function requiresPayment(Package $package): bool
    {
        if (! ($package->requires_payment ?? true)) {
            return false;
        }

        return (float) $package->price > 0;
    }

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
            // Restore auto-renew, but never on a lifetime plan (it has no
            // renewal cycle) — preserves the defense-in-depth from subscribe().
            $autoRenew = $subscription->package?->billing_period !== Period::Lifetime;

            $subscription = $this->subscriptionRepo->update($subscription, [
                'status'                    => SubscriptionStatus::Active,
                'cancelled_at'              => null,
                'cancellation_effective_at' => null,
                'cancellation_reason'       => null,
                'auto_renew'                => $autoRenew,
            ]);

            $this->eventStore->append($subscription, 'subscription.resumed');
            $this->dispatchAfterCommit(fn () => SubscriptionResumed::dispatch($subscription));

            return $subscription;
        });
    }

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

    /**
     * Convert a trial to a paid subscription. Anchors the first paid period
     * to the conversion moment (so the customer is not handed the remainder
     * of the trial-subscribe period for free) and issues the first invoice
     * for priced plans. The invoice is `initial`, so paying it records the
     * payment without advancing the just-anchored period.
     */
    public function convertTrial(Subscription $subscription): Subscription
    {
        if (! $subscription->isOnTrial()) {
            return $subscription;
        }

        return $this->db->connection()->transaction(function () use ($subscription) {
            $now = now();
            $package = $subscription->package;
            $periodEnd = $this->calculatePeriodEnd($now->copy(), $package);

            $subscription = $this->subscriptionRepo->update($subscription, [
                'status'               => SubscriptionStatus::Active,
                'trial_converted_at'   => $now,
                'activated_at'         => $subscription->activated_at ?? $now,
                'current_period_start' => $now,
                'current_period_end'   => $periodEnd,
                'ends_at'              => $periodEnd,
            ]);

            $this->usageRepo->reanchorPeriods($subscription, $now);

            $this->eventStore->append($subscription, 'trial.converted');

            if ($this->requiresPayment($package)) {
                $this->billing->issueInitialInvoice($subscription);
            }

            // A converted trial transitions OnTrial → Active, so it flows
            // through the standard activation event path too. The initial
            // invoice (priced plans) is freshly issued and still unpaid, so
            // pass a null invoice — matching the free-plan activation in
            // provision() — rather than implying a payment drove this.
            $this->dispatchAfterCommit(function () use ($subscription) {
                TrialConverted::dispatch($subscription);
                SubscriptionActivated::dispatch($subscription, null);
            });

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

    /**
     * Pause a subscription and freeze the clock. The remaining access time
     * (seconds until ends_at) is banked in metadata so unpause() can add it
     * back — a paused subscription doesn't silently burn the customer's
     * remaining paid time. Lifetime / open-ended subs bank nothing.
     */
    public function pause(Subscription $subscription): Subscription
    {
        if ($subscription->isPaused()) {
            return $subscription;
        }

        return $this->db->connection()->transaction(function () use ($subscription) {
            $now = now();

            $remaining = null;
            if ($subscription->ends_at && $subscription->ends_at->isFuture()) {
                $remaining = $subscription->ends_at->getTimestamp() - $now->getTimestamp();
            }

            $metadata = $subscription->metadata ?? [];
            $metadata['paused_remaining_seconds'] = $remaining;
            $metadata['paused_at'] = (string) $now;

            $subscription = $this->subscriptionRepo->update($subscription, [
                'status'   => SubscriptionStatus::Paused,
                'metadata' => $metadata,
            ]);

            $this->eventStore->append($subscription, 'subscription.paused', [
                'remaining_seconds' => $remaining,
            ]);
            $this->dispatchAfterCommit(fn () => SubscriptionPaused::dispatch($subscription));

            return $subscription;
        });
    }

    /**
     * Resume a paused subscription, adding back the access time banked at
     * pause so the customer regains exactly what they had left.
     */
    public function unpause(Subscription $subscription): Subscription
    {
        if (! $subscription->isPaused()) {
            return $subscription;
        }

        return $this->db->connection()->transaction(function () use ($subscription) {
            $now = now();
            $metadata = $subscription->metadata ?? [];
            $remaining = $metadata['paused_remaining_seconds'] ?? null;

            $data = ['status' => SubscriptionStatus::Active];

            if ($remaining !== null) {
                $newEnd = $now->copy()->addSeconds((int) $remaining);
                $data['ends_at'] = $newEnd;
                $data['current_period_end'] = $newEnd;
            }

            unset($metadata['paused_remaining_seconds'], $metadata['paused_at']);
            $data['metadata'] = $metadata;

            $subscription = $this->subscriptionRepo->update($subscription, $data);

            $this->eventStore->append($subscription, 'subscription.unpaused');
            $this->dispatchAfterCommit(fn () => SubscriptionUnpaused::dispatch($subscription));

            return $subscription;
        });
    }

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

            $subscriber = $subscription->subscriber;
            if (! $subscriber instanceof Subscribable) {
                throw SubscriptionException::subscriberNotSubscribable($subscription);
            }

            $this->cancel($subscription, immediate: true, reason: 'Plan switch');

            // gatePayment: false — a switch carries an active customer onto a
            // new plan; access continues and no initial invoice is issued.
            $newSubscription = $this->provision($subscriber, $newPackage, $carryTrial, gatePayment: false);

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

    /**
     * Change an active subscription's plan in place (SAME subscription row),
     * keeping the period and usage counters. Classifies by normalized
     * monthly price:
     *
     *  - Upgrade / lateral → applied immediately; the prorated delta for the
     *    remainder of the current period is billed on a `proration` invoice.
     *  - Downgrade         → deferred to period end via scheduleDowngrade(),
     *    since the current period is already paid for.
     *
     * Distinct from switchPlan(), which cancels the old subscription and
     * creates a new one. Use changePlan() for upgrades on a live plan.
     */
    public function changePlan(Subscription $subscription, Package $newPackage, bool $prorate = true): Subscription
    {
        $current = $subscription->package;

        if ($current && $current->id === $newPackage->id) {
            return $subscription;
        }

        // Downgrade → take effect at period end (current period already paid).
        if ($current && $this->normalizedMonthlyPrice($newPackage) < $this->normalizedMonthlyPrice($current)) {
            return $this->scheduleDowngrade($subscription, $newPackage);
        }

        return $this->applyUpgrade($subscription, $newPackage, $prorate);
    }

    /**
     * Apply an immediate in-place upgrade: re-snapshot features (carrying
     * usage forward), bill the prorated delta, and emit SubscriptionPlanChanged.
     */
    protected function applyUpgrade(Subscription $subscription, Package $newPackage, bool $prorate): Subscription
    {
        return $this->db->connection()->transaction(function () use ($subscription, $newPackage, $prorate) {
            $oldPackage = $subscription->package;

            $defaultCurrency = (string) Config::get('tashil.currency', 'USD');
            $oldCurrency = $oldPackage?->currency ?: $defaultCurrency;
            $newCurrency = $newPackage->currency ?: $defaultCurrency;

            if ($prorate && $oldPackage && $oldCurrency !== $newCurrency) {
                throw SubscriptionException::cannotProrateAcrossCurrencies($subscription, $oldCurrency, $newCurrency);
            }

            $delta = $prorate && $oldPackage
                ? $this->prorationDelta($subscription, $oldPackage, $newPackage)
                : 0.0;

            $subscription = $this->subscriptionRepo->update($subscription, [
                'package_id' => $newPackage->id,
            ]);

            $this->subscriptionRepo->resyncFeatures($subscription, $newPackage, carryUsage: true);

            $this->eventStore->append($subscription, 'subscription.plan_changed', [
                'old_package_id'   => $oldPackage?->id,
                'new_package_id'   => $newPackage->id,
                'new_package_slug' => $newPackage->slug,
                'proration_amount' => $delta,
            ]);

            $invoice = null;
            $minProration = (float) Config::get('tashil.billing.min_proration_amount', 0.50);
            if ($delta >= $minProration) {
                $invoice = $this->billing->generateInvoice(
                    $subscription,
                    $delta,
                    InvoiceKind::Proration,
                    periodStart: now(),
                    periodEnd: $subscription->current_period_end,
                );
            }

            $this->dispatchAfterCommit(fn () => SubscriptionPlanChanged::dispatch(
                $subscription,
                $oldPackage,
                $newPackage,
                $delta,
                $invoice,
            ));

            return $subscription;
        });
    }

    /**
     * Prorated charge for switching from $old to $new for the unconsumed
     * remainder of the current period: (new − old) × remaining-fraction.
     * Returns 0 when there is no bounded current period (lifetime / not yet
     * started).
     */
    protected function prorationDelta(Subscription $subscription, Package $old, Package $new): float
    {
        $start = $subscription->current_period_start;
        $end = $subscription->current_period_end;

        if (! $start || ! $end) {
            return 0.0;
        }

        $total = $end->getTimestamp() - $start->getTimestamp();
        if ($total <= 0) {
            return 0.0;
        }

        $remaining = max(0, $end->getTimestamp() - now()->getTimestamp());
        $fraction = min(1.0, $remaining / $total);

        $oldCredit = (float) $old->price * $fraction;
        $newCharge = (float) $new->price * $fraction;

        return round($newCharge - $oldCredit, 2);
    }

    /**
     * Package price normalized to a monthly figure, used to classify a plan
     * change as an upgrade or downgrade across differing billing cadences.
     */
    protected function normalizedMonthlyPrice(Package $package): float
    {
        $interval = max(1, (int) $package->billing_interval);
        $price = (float) $package->price;

        return match ($package->billing_period) {
            Period::Day      => $price * 30 / $interval,
            Period::Week     => $price * 4.33 / $interval,
            Period::Month    => $price / $interval,
            Period::Year     => $price / (12 * $interval),
            Period::Lifetime => 0.0,
            default          => $price / $interval,
        };
    }

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

    /**
     * Advance current_period_end by one billing period. Called by
     * InvoiceObserver when an invoice is marked paid.
     */
    public function advancePeriod(Subscription $subscription): Subscription
    {
        // M1 guard: only ever advance a live subscription. A paid invoice on
        // a cancelled / expired / paused subscription must not silently shift
        // its period (recovery goes through reactivate(), not advancePeriod()).
        if (! in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::OnTrial], true)) {
            return $subscription;
        }

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
}
