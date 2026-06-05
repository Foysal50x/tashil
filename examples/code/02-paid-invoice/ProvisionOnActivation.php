<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use Foysal50x\Tashil\Events\SubscriptionActivated;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Reacts to a Pending subscription becoming Active (initial invoice paid, or a
 * free / requires_payment=false plan that activated immediately).
 *
 * This is the right place to provision the account: create API keys, flip
 * feature flags in your own tables, send the welcome email, etc. By the time
 * this runs the subscription is durably Active and its billing period is
 * anchored — Tashil dispatched the event AFTER the DB commit.
 *
 * $event->invoice is the paid initial invoice, or null for a free activation.
 */
class ProvisionOnActivation implements ShouldQueue
{
    public function handle(SubscriptionActivated $event): void
    {
        $subscription = $event->subscription;

        /** @var User $subscriber */
        $subscriber = $subscription->subscriber;

        Log::info('Subscription activated — provisioning account', [
            'subscription_id' => $subscription->id,
            'package'         => $subscription->package->slug,
            'period_start'    => $subscription->current_period_start,
            'period_end'      => $subscription->current_period_end,
            'paid_invoice'    => $event->invoice?->id,   // null on free activation
        ]);

        // Example provisioning side-effects (host-specific):
        // $subscriber->issueApiKey();
        // $subscriber->notify(new WelcomeToPlanNotification($subscription->package));
        // $subscriber->workspaces()->update(['plan' => $subscription->package->slug]);
    }
}
