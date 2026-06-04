<?php

declare(strict_types=1);

namespace App\Providers;

use App\Billing\WalletMeteredBilling;
use App\Listeners\ChargeRenewalInvoice;
use App\Listeners\ProvisionOnActivation;
use App\Listeners\RetryDunningCharge;
use App\Listeners\RevokeAccessOnSuspend;
use App\Listeners\SendTrialEndingReminder;
use App\Models\Team;
use Foysal50x\Tashil\Contracts\MeteredBilling;
use Foysal50x\Tashil\Events\InvoiceIssued;
use Foysal50x\Tashil\Events\SubscriptionActivated;
use Foysal50x\Tashil\Events\SubscriptionPastDue;
use Foysal50x\Tashil\Events\SubscriptionSuspended;
use Foysal50x\Tashil\Events\TrialEnding;
use Foysal50x\Tashil\Facades\Tashil;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the host into Tashil. Everything here is host responsibility —
 * the package ships none of it, by design.
 *
 *  - register(): bind your MeteredBilling implementation (Pattern B).
 *  - boot():     register the subscribable resolver + your event listeners
 *                that actually move money / provision access.
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bind the metered-billing provider. Without this, metered features
        // fall back to NullMeteredBilling (reads safe-deny, charge() throws).
        // Skip this line if you instead implement MeteredBilling directly on
        // your subscriber model (Pattern A).
        $this->app->bind(MeteredBilling::class, WalletMeteredBilling::class);
    }

    public function boot(): void
    {
        $this->registerSubscribableResolver();
        $this->registerBillingListeners();
    }

    /**
     * The route middleware (subscribed / plan / feature) and Blade directives
     * (@subscribed / @plan / @feature / @onTrial) ask Tashil "who is the
     * current subscriber?". By default that is auth()->user().
     *
     * For a team / tenant SaaS, point it at the active tenant instead. The
     * returned object must `instanceof Subscribable` or the middleware treats
     * the request as unauthenticated (403).
     */
    private function registerSubscribableResolver(): void
    {
        Tashil::resolveSubscribableUsing(fn () => Team::current() ?? auth()->user());
    }

    /**
     * Tashil issues invoices and emits lifecycle events but never charges a
     * card. These listeners are where YOUR app reacts — charging the gateway,
     * provisioning access, sending dunning email, revoking on suspend.
     *
     * (In a fresh Laravel app you can also auto-discover listeners; explicit
     * registration is shown here so the wiring is obvious.)
     */
    private function registerBillingListeners(): void
    {
        // Trial about to end → nudge the customer to add a card.
        Event::listen(TrialEnding::class, SendTrialEndingReminder::class);

        // Initial invoice paid → Pending becomes Active → provision the account.
        Event::listen(SubscriptionActivated::class, ProvisionOnActivation::class);

        // A renewal invoice was issued → charge the saved card, then markAsPaid.
        Event::listen(InvoiceIssued::class, ChargeRenewalInvoice::class);

        // Renewal went unpaid → dunning escalates → retry the charge.
        Event::listen(SubscriptionPastDue::class, RetryDunningCharge::class);

        // Retries exhausted → access cut → revoke API keys / sessions.
        Event::listen(SubscriptionSuspended::class, RevokeAccessOnSuspend::class);
    }
}
