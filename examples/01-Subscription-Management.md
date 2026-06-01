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

## 5. Switch plan

Cancels the old subscription immediately, creates a new one with the target package, and emits `SubscriptionSwitched`. Trial state is carried forward if both the old subscription is mid-trial and the new package offers a trial.

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
$user->pauseSubscription();   // status: Paused
$user->unpauseSubscription(); // status: Active
```

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
