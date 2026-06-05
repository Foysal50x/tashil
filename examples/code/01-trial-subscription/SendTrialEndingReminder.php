<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\User;
use App\Notifications\TrialEndingNotification;
use Foysal50x\Tashil\Events\TrialConverted;
use Foysal50x\Tashil\Events\TrialEnding;
use Foysal50x\Tashil\Events\TrialExpired;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * The trial lifecycle emits three events you typically react to. This file
 * groups the three matching listeners so the whole flow is visible at once.
 * Register them in AppServiceProvider (see 00-setup).
 *
 *   TrialEnding   — fired by `tashil:mark-trials-ending` N days before expiry
 *                   (N = tashil.trial.warn_days). Nudge the customer.
 *   TrialConverted— fired by convertTrial(). Trial became a paying customer.
 *   TrialExpired  — fired by `tashil:expire-trials` when a trial lapsed
 *                   without converting. Access is already gone; win them back.
 *
 * `ShouldQueue` pushes the work off the request/cron thread. Tashil dispatches
 * domain events AFTER the DB commit (tashil.events.async), so by the time a
 * listener runs the state transition is already durable.
 */
class SendTrialEndingReminder implements ShouldQueue
{
    public function handle(TrialEnding $event): void
    {
        /** @var User $subscriber */
        $subscriber = $event->subscription->subscriber;

        // $event->daysRemaining is how many days until trial_ends_at.
        $subscriber->notify(new TrialEndingNotification(
            daysRemaining: $event->daysRemaining,
            trialEndsAt: $event->subscription->trial_ends_at,
        ));
    }
}

/**
 * A converted trial — start onboarding the paying customer, fire analytics, etc.
 */
class CelebrateTrialConversion implements ShouldQueue
{
    public function handle(TrialConverted $event): void
    {
        Log::info('Trial converted to paid', [
            'subscription_id' => $event->subscription->id,
            'package'         => $event->subscription->package->slug,
            'converted_at'    => $event->subscription->trial_converted_at,
        ]);
    }
}

/**
 * A lapsed trial — access is already revoked by the time this runs. Good place
 * for a win-back campaign.
 */
class HandleTrialExpired implements ShouldQueue
{
    public function handle(TrialExpired $event): void
    {
        /** @var User $subscriber */
        $subscriber = $event->subscription->subscriber;

        // $subscriber->notify(new TrialExpiredWinbackNotification());
        Log::info('Trial expired without conversion', [
            'subscription_id' => $event->subscription->id,
        ]);
    }
}
