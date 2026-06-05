# Subscription Management

How to manage subscriptions with the `tahsil` package.

## 0. Make your subscriber model Subscribable

Any Eloquent model can be a subscriber by implementing the `Subscribable` contract and using the `HasSubscriptions` trait:

```php
use Foysal50x\Tashil\Contracts\Subscribable;
use Foysal50x\Tashil\Traits\HasSubscriptions;

class User extends Authenticatable implements Subscribable
{
    use HasSubscriptions;
}
```

`implements Subscribable` is required — every library type-hint accepts `Subscribable`, not Eloquent's `Model`. The trait provides default implementations for `subscriptions`, `resolveSubscription`, `getSubscriberKey`, and `getSubscriberType`.

Override `resolveSubscription()` if your model holds multiple subscriptions and you want to control which one represents "the active one":

```php
public function resolveSubscription(): ?Subscription
{
    return $this->subscriptions()->valid()
        ->where('package_id', $this->currentWorkspace->preferredPackageId)
        ->first();
}
```

## 1. Subscribe a user

```php
use Foysal50x\Tashil\Models\Package;
use App\Models\User;

$user    = User::find(1);
$package = Package::where('slug', 'pro-monthly')->first();

$subscription = app('tashil')->subscription()->subscribe($user, $package);

// Or with a trial (uses $package->trial_days):
$subscription = app('tashil')->subscription()->subscribe($user, $package, withTrial: true);
```

A `SubscriptionCreated` event is dispatched and a `subscription.created` row is appended to the event store.

Subscribing twice for the same subscriber throws `Foysal50x\Tashil\Exceptions\SubscriptionException` (`alreadySubscribed`) — move plans with `changePlan()` / `switchPlan()`, or cancel the current one first.

### What status do you get? (activate-on-payment)

`subscribe()` does **not** always return an `Active` subscription. The result depends on the package's `requires_payment` flag (a priced, payment-required plan is gated behind its first paid invoice):

| Package | `subscribe()` result | Invoice issued |
|---|---|---|
| `withTrial` + `trial_days > 0` | `OnTrial` — access granted | none (billed at conversion) |
| `price > 0` and `requires_payment = true` (default) | `Pending` — **no access yet** | an `initial` invoice |
| `price = 0`, or `requires_payment = false` | `Active` immediately | none |

`requires_payment` is the package's own flag — authoritative at runtime. It is seeded at creation from `tashil.billing.activate_on_payment` (default `true`) when you don't set it, so out of the box priced plans gate. Create the package with `requires_payment = false` (or `Tashil::package('pro')->requiresPayment(false)`) for free / offline / enterprise-invoiced plans that should activate immediately. See [docs/09-Billing-Lifecycle.md](../docs/09-Billing-Lifecycle.md).

### Activate a gated subscription (pay the initial invoice)

A `Pending` subscription has no access and no period yet. It becomes `Active` when its `initial` invoice is paid — Tashil never moves money, so the host charges the card and records the payment:

```php
use Foysal50x\Tashil\Facades\Tashil;

// the outstanding invoice for this subscription, via the billing API:
$invoice = Tashil::billing()->pendingInvoice($subscription);

// after the host's gateway confirms the charge — records the transaction AND
// settles the invoice in one idempotent call:
Tashil::billing()->recordPayment($invoice, gateway: 'stripe', transactionId: 'ch_…');

$subscription->refresh(); // status: Active; period anchored to invoice.paid_at
```

Paying anchors `current_period_start` / `current_period_end` / `activated_at` to the payment moment (not to subscribe time) — the customer gets the full paid period, never a free head-start. A `SubscriptionActivated` event fires.

## 2. Check status

```php
$user->subscribed();          // true if Active, OnTrial, or PendingCancellation (grace)
$user->onPlan('pro-monthly'); // true if currently subscribed to this slug
$user->onTrial();             // strict: status==OnTrial AND trial_ends_at in future
$user->paused();              // status==Paused
$user->pendingChange();       // returns the queued target Package, or null
```

## 3. Cancel — grace vs immediate

Grace cancel (default): the subscription stays accessible until `ends_at` / `current_period_end`. Status becomes `PendingCancellation`. The `tashil:expire-subscriptions` job promotes it to `Expired` once the window elapses.

```php
app('tashil')->subscription()->cancel($subscription);
// status: PendingCancellation, auto_renew: false, ends_at preserved
```

Immediate cancel: revokes access now.

```php
app('tashil')->subscription()->cancel($subscription, immediate: true, reason: 'User request');
// status: Cancelled, ends_at: now
```

`SubscriptionCancelled` carries the `immediate` flag in its payload.

## 4. Resume

Only `PendingCancellation` subs whose `ends_at` is still in the future are resumable.

```php
$user->resumeSubscription(); // status returns to Active
```

## 5. Change a plan — in place vs. switch

Two distinct operations. Pick by whether you want to keep the same subscription row (and its usage/period) or start fresh.

### `changePlan()` — in-place upgrade with proration (same row)

Keeps the SAME subscription, period, and usage counters. Classifies by normalized monthly price:

- **Upgrade / lateral** → applied immediately; the prorated price difference for the remainder of the current period is billed on a `proration` invoice (skipped if below `tashil.billing.min_proration_amount`). Features re-snapshot, carrying usage forward. Emits `SubscriptionPlanChanged`.
- **Downgrade** → deferred to period end via `scheduleDowngrade()` (the current period is already paid for).

```php
$enterprise = Package::where('slug', 'enterprise')->first();

// prorate defaults to true; pass false to change plan without billing the delta
$subscription = app('tashil')->subscription()->changePlan($subscription, $enterprise);

// trait equivalent:
$user->changePlan($enterprise);
```

Proration is single-currency only — changing to a plan priced in a different currency throws `SubscriptionException` (`cannotProrateAcrossCurrencies`); cancel and resubscribe instead.

### `switchPlan()` — cancel + recreate (new row)

Cancels the old subscription immediately, creates a new one with the target package, and emits `SubscriptionSwitched`. Trial state is carried forward if the old subscription is mid-trial and the new package offers a trial. Access continues (no re-gating, no double-bill).

```php
$enterprise = Package::where('slug', 'enterprise')->first();
$newSub = app('tashil')->subscription()->switchPlan($subscription, $enterprise);
```

## 6. Schedule a downgrade (apply at period end)

```php
$user->scheduleDowngrade($basicPackage);
// stores pending_package_id + pending_change_at = current_period_end
```

The `tashil:apply-pending-changes` job invokes `switchPlan` when the moment arrives, dispatching `PendingChangeApplied`.

Cancel a scheduled change:

```php
app('tashil')->subscription()->cancelPendingChange($subscription);
```

## 7. Pause / Unpause

```php
$user->pauseSubscription();   // status: Paused (access revoked)
$user->unpauseSubscription(); // status: Active
```

Pausing banks the **time remaining** in the current period (`metadata.paused_remaining_seconds`) and emits `SubscriptionPaused`; unpausing restores that remainder from the moment of resume (so a pause doesn't burn paid days) and emits `SubscriptionUnpaused`. A `Paused` subscription is not `isValid()`, so feature checks and the `subscribed` middleware deny access while paused.

## 8. Trial transitions

```php
app('tashil')->subscription()->convertTrial($subscription);  // status: OnTrial → Active
app('tashil')->subscription()->expireTrial($subscription);   // status: OnTrial → Expired (via job too)
```

Scheduled jobs handle the unattended path: `tashil:expire-trials` and `tashil:mark-trials-ending`.

## 9. Gate routes with middleware

Three route middleware ship and auto-register:

```php
Route::middleware('subscribed')->group(fn () => /* requires a valid subscription */);
Route::middleware('plan:pro')->group(fn () => /* requires the pro plan */);
Route::middleware('feature:api-calls')->group(fn () => /* requires the api-calls feature */);
```

All three abort `403` on failure. They resolve the subscribable via `Tashil::resolveSubscribable()` — by default `auth()->user()`. For multi-tenant or team-based apps, override the resolver in `AppServiceProvider::boot`:

```php
use Foysal50x\Tashil\Facades\Tashil;

Tashil::resolveSubscribableUsing(fn () => Team::current());
```

## 10. Gate views with Blade directives

The same resolver powers four Blade conditional directives:

```blade
@subscribed
    {{-- visible only if a valid subscription exists --}}
@endsubscribed

@plan('pro')
    {{-- visible only on the pro plan --}}
@endplan

@feature('api-calls')
    {{-- visible only when the feature check passes --}}
@endfeature

@onTrial
    {{-- visible only when status==OnTrial AND trial_ends_at is in the future --}}
@endonTrial
```

## 11. Replay history

Every state transition is appended to the per-subscription event log with a monotonic `sequence_num`.

```php
$subscription->events()
    ->orderBy('sequence_num')
    ->get()
    ->each(fn ($e) => print "{$e->sequence_num}: {$e->event_type}\n");
```

Or as-of a moment:

```php
app(\Foysal50x\Tashil\Contracts\SubscriptionEventRepositoryInterface::class)
    ->listUpTo($subscription, now()->subDays(7));
```

## 12. Past-due, suspension & reactivation (dunning)

When a renewal invoice goes unpaid past its due date, the `tashil:process-dunning` command drives the recovery lifecycle:

```text
Active ──(retry milestone)──▶ PastDue ──(retries exhausted)──▶ Suspended ──(grace elapses)──▶ Expired
```

Each step fires `SubscriptionPastDue` / `SubscriptionSuspended` (and the existing `InvoiceOverdue`) so the host can re-attempt the charge — Tashil never charges. Whether a `PastDue` subscription keeps access during the retry window is config (`tashil.dunning.keep_access_while_past_due`); `Suspended` always loses access. See [05-Console-Commands.md](./05-Console-Commands.md) for the schedule and config.

**Reactivation is automatic on payment.** Paying the overdue invoice at any point before expiry recovers the subscription — `InvoiceObserver` calls `SubscriptionService::reactivate()`, which restores `Active` and clears the dunning counters:

```php
$invoice->markAsPaid();   // PastDue / Suspended → Active, dunning state cleared
$subscription->refresh();
```

A `SubscriptionReactivated` event fires. A still-future period is kept as-is; a period that already lapsed starts fresh from payment.
