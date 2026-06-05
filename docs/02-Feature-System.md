# Feature System

Tahsil separates **what features exist** (catalog), **what features a plan offers** (configuration), **what a subscription was given** (immutable snapshot), and **what the subscription has used** (mutable counter). Each concern lives in its own table.

## Five feature types

| Type | Enum | Behavior |
|---|---|---|
| Boolean | `FeatureType::Boolean` | Pure on/off. `value` stores `"true"`/`"false"`. No counter needed. |
| Limit | `FeatureType::Limit` | Numeric quota with hard enforcement. Counter increments atomically; over-limit increments are rejected. |
| Consumable | `FeatureType::Consumable` | Tracked usage without a hard ceiling (or with one — the counter still records). Useful for soft-limit metering. |
| Enum | `FeatureType::Enum` | Named option (e.g. tier label). Read via `featureValue()`; no counter semantics. |
| Metered | `FeatureType::Metered` | Charged per unit consumed, deducted from the subscriber's balance via a host-implemented `MeteredBilling`. The pivot `value` is the **unit price** (decimal string); the counter still tracks units for analytics but has no `limit_value` cap. See [Metered features](#metered-features). |

The type is captured both on the catalog `Feature` and on the per-subscription snapshot — the snapshot is what enforcement reads, so renaming or repurposing a `Feature` in the catalog won't retroactively change an existing subscription's contract.

## Reset cadence

Counters can reset on a regular cadence via `ResetPeriod`:

| Reset | Behavior |
|---|---|
| `never` | Counter persists forever. Use for lifetime quotas, storage. |
| `daily` / `weekly` / `monthly` / `yearly` | The `tashil:reset-quotas` job zeroes the counter when `period_end` has elapsed and advances `period_start`/`period_end`. |

Anchor rule: the next period is computed from the **previous** `period_end`, not from `now()`. So if the job fires an hour late, the cadence does not drift.

## Defining features

```php
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Enums\ResetPeriod;

Tashil::feature('api-requests')
    ->name('API Requests')
    ->limit()
    ->metadata(['warn_at_pct' => 80])
    ->create();

Tashil::feature('dark-mode')
    ->name('Dark Mode')
    ->boolean()
    ->create();

Tashil::feature('storage-gb')
    ->name('Storage (GB)')
    ->consumable()
    ->create();

Tashil::feature('ai-tokens')
    ->name('AI Tokens')
    ->metered()
    ->create();
```

Set the reset cadence either via the model directly or by attaching a `reset_period` to the feature itself (the column is on `tashil_features` and is also snapshotted onto each subscription).

```php
$feature = Tashil::feature('api-requests')->limit()->create();
$feature->update(['reset_period' => ResetPeriod::Monthly]);
```

## Attaching to a package

The pivot row `tashil_package_feature` carries:

- `value` — limit amount, boolean string, or enum tag.
- `is_available` — when false, the feature is **not** synced on subscribe (lets you stage a feature on a plan without rolling it out yet).
- `sort_order` — for catalog UIs.

```php
Tashil::package('pro')
    ->name('Pro')
    ->price(29)->monthly()
    ->feature($api,  value: '10000')
    ->feature($dark, value: 'true')
    ->feature($storage, value: '50')          // 50 GB
    ->create();
```

## What happens on subscribe

`SubscriptionService::subscribe()` runs inside one DB transaction. For each available feature on the package, it writes:

1. One row in `tashil_subscription_features` (immutable snapshot — `feature_slug`, `feature_type`, `value`, `reset_period`, `added_at`).
2. One row in `tashil_feature_usages` (the counter — `usage=0`, `period_start=now`, `period_end=now + first window`). `limit_value` is populated **only for Limit features** (numeric pivot value coerced to float). Boolean, Consumable, Enum, and Metered counters all carry `limit_value = NULL` — they're either uncapped by design (Consumable, Metered) or don't use a counter for gating (Boolean, Enum).

It also appends `subscription.created` to the event store. (Under the default activate-on-payment model a priced plan subscribes as `Pending`: the snapshot + counter rows are written but gated — no feature access until the initial invoice is paid and `activate()` re-anchors the counters. See [09-Billing-Lifecycle.md](09-Billing-Lifecycle.md).)

If the plan changes later, the old snapshot rows are stamped `superseded_at = now()` and new snapshot rows are inserted with `superseded_at = null`. Both sets stay in the table forever — the snapshot is the audit trail of "what features did this subscription have at time T?". Two mechanisms:

- **`switchPlan()` / `applyPendingChange()`** cancel the old subscription and create a new one (fresh counters).
- **`changePlan()`** (in-place upgrade/lateral) supersedes + re-writes snapshots on the **same** subscription and **carries usage counters forward** via `resyncFeatures(carryUsage: true)` — the usage value is kept while the cap (`limit_value`) and reset cadence update to the new plan. Counters for features the new plan drops are left in place (no current snapshot ⇒ gating denies) and never deleted.

## Checking access

```php
$user->hasFeature('dark-mode');                       // boolean snapshot resolution
app('tashil')->usage()->check($sub, 'api-requests');  // limit check + subscription validity + feature.is_active gate
```

`UsageService::check()` denies access when any of these is false:

- `Subscription::isValid()` — covers expired, cancelled, paused, suspended.
- `Feature::is_active` on the catalog — global kill-switch.
- Limit-type features: `usage + amount > limit_value`.

## Consuming a limit

```php
$user->useFeature('api-requests', 1);
// or
app('tashil')->usage()->increment($sub, 'api-requests', 1);
```

The increment runs as a single conditional UPDATE:

```sql
UPDATE tashil_feature_usages
SET usage = usage + :amount
WHERE id = :id
  AND (limit_value IS NULL OR usage + :amount <= limit_value)
```

If `affected_rows` is 0, the increment was rejected — the counter is untouched and `increment()` returns `false`. Two concurrent callers cannot both pass the check; the row-level lock embedded in the conditional UPDATE serializes them.

On success, `tashil_usage_logs` records `previous_usage` and `new_usage`, and `UsageLimitWarning` is dispatched **exactly once per period** when the counter first crosses 80% of the limit.

## Absolute reporting (storage-style)

For features where the application knows the absolute usage (e.g. bucket size in GB), use `reportStorage`:

```php
$user->reportStorage('storage-gb', 38.5);
```

This sets the counter directly (no delta), logs the change with `operation = report`, and fires `UsageLimitWarning` only when the new value crosses 80% (not on every report).

## Metered features

Metered features charge per unit consumed against the subscriber's balance. Tahsil never owns the balance — every charge goes through a host-implemented `MeteredBilling`. Without one bound, `useFeature('metered-thing')` throws `MeteredBillingNotConfiguredException` so misconfiguration fails loud.

### Defining

```php
use Foysal50x\Tashil\Facades\Tashil;

$aiTokens = Tashil::feature('ai-tokens')
    ->name('AI Tokens')
    ->metered()
    ->create();

Tashil::package('payg')
    ->name('Pay-as-you-go')
    ->price(0)->monthly()
    ->feature($aiTokens, value: '0.001')   // 0.001 USD per token
    ->create();
```

The pivot `value` is the **unit price** (decimal string) in the package's currency. The snapshot captures it at subscribe time, so changing the catalog price later doesn't retro-price existing subscriptions.

### Implementing MeteredBilling — two patterns

Tashil doesn't force a service/repository style. Pick whichever fits your codebase.

`UsageService` resolves the implementation per consume:

1. If `$subscription->subscriber` (the Subscribable) **implements `MeteredBilling` itself**, that instance is used — no DI required.
2. Otherwise the container-bound `MeteredBilling` implementation is used (the standalone service pattern).

Either path satisfies the same interface, so the consumption flow, idempotency contract, and events are identical.

#### Pattern A — Self-implementing model (no extra service)

Suited to small apps where the subscriber model already owns its balance. Add the interface and methods directly on `User` / `Team` / whatever your `Subscribable` is.

```php
namespace App\Models;

use Foysal50x\Tashil\Contracts\MeteredBilling;
use Foysal50x\Tashil\Contracts\Subscribable;
use Foysal50x\Tashil\Traits\HasSubscriptions;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements Subscribable, MeteredBilling
{
    use HasSubscriptions;

    public function getBalance(Subscribable $subscriber, string $currency): float
    {
        return (float) $this->wallets()->forCurrency($currency)->value('balance');
    }

    public function hasSufficientBalance(Subscribable $subscriber, string $currency, float $amount): bool
    {
        return $this->getBalance($subscriber, $currency) >= $amount;
    }

    public function charge(Subscribable $subscriber, string $currency, float $amount, array $context = []): bool
    {
        // $subscriber is always $this when self-implementing — the
        // parameter exists for signature uniformity with Pattern B.
        return $this->wallets()->forCurrency($currency)->debit(
            $amount,
            idempotencyKey: $context['idempotency_key'],
            meta: $context,
        );
    }
}
```

No container binding needed. The default `NullMeteredBilling` is never consulted because the subscriber satisfies the interface itself.

#### Pattern B — Standalone class bound via the container

Suited to larger apps that already isolate billing in a service layer, or when multiple subscriber models share the same billing logic.

```php
namespace App\Billing;

use Foysal50x\Tashil\Contracts\MeteredBilling;
use Foysal50x\Tashil\Contracts\Subscribable;

class WalletMeteredBilling implements MeteredBilling
{
    public function getBalance(Subscribable $subscriber, string $currency): float
    {
        return $subscriber->wallet($currency)->balance();
    }

    public function hasSufficientBalance(Subscribable $subscriber, string $currency, float $amount): bool
    {
        return $this->getBalance($subscriber, $currency) >= $amount;
    }

    public function charge(Subscribable $subscriber, string $currency, float $amount, array $context = []): bool
    {
        return $subscriber->wallet($currency)->debit(
            $amount,
            idempotencyKey: $context['idempotency_key'],
            meta: $context,
        );
    }
}
```

Bind in a service provider:

```php
// AppServiceProvider::register
$this->app->bind(
    \Foysal50x\Tashil\Contracts\MeteredBilling::class,
    \App\Billing\WalletMeteredBilling::class,
);
```

#### Mixing the two

A host can mix both: e.g. `Team` self-implements `MeteredBilling` (team-scoped wallet), while `User` doesn't — and the container binding handles the `User` case. Per-consume resolution makes this transparent: each subscriber's billing path is decided by what *that* subscriber implements, not by global config.

### Idempotency

Both patterns receive the same `idempotency_key` in `$context` (format: `metered:{subscription_id}:{feature_id}:{uuid}`). The implementation **must** dedupe on this key so a retried call doesn't double-charge — Tashil itself doesn't retry today, but provider integrations (webhooks, queued jobs) frequently do.

### Consumption flow

```php
// 100 × 0.001 = 0.10 USD deducted from balance.
// Returns false if the provider refuses the charge (insufficient balance,
// gateway error, etc.). On rejection nothing is written — the counter
// stays put, no usage_log row, no event.
$user->useFeature('ai-tokens', 100);
```

What happens on success:

1. `MeteredBilling::charge` returns true.
2. The counter advances by `units` (analytics) inside a DB transaction.
3. One `tashil_usage_logs` row is written with `operation=consume`, the metered amount + currency in `metadata`, and the human-readable charge description.
4. One `tashil_subscription_events` row is appended (`event_type='usage.metered_charged'`) carrying `feature_id`, `units`, `unit_price`, `amount`, `currency`.
5. `MeteredCharged` event dispatches after DB commit.

On rejection:

1. `MeteredBilling::charge` returns false.
2. Nothing is written to counter, log, or event store.
3. `MeteredChargeRejected` dispatches after DB commit so the host can prompt the user to top up.

### Context passed to charge()

Both patterns receive the same `$context` array:

```php
[
    'idempotency_key' => string,   // see below
    'subscription_id' => int,
    'feature_id'      => int,
    'feature_slug'    => string,
    'units'           => float,    // raw amount passed to useFeature()
    'unit_price'      => float,    // snapshot unit_price
]
```

### Idempotency keys — caller-supplied vs auto-generated

The `idempotency_key` in `$context` MUST be honored by the provider's `charge` implementation. Where it comes from depends on how `useFeature` was called:

```php
// Caller-supplied — recommended for any retryable call path. The same
// token reaching the provider twice = same logical consume = MUST NOT
// double-charge. Typical sources: request ID, queued-job UUID, an
// idempotency token from the upstream HTTP client.
$user->useFeature('ai-tokens', 100, idempotencyKey: $request->header('X-Idempotency-Key'));

// Auto-generated — fresh UUID per call (format: metered:{sub}:{feature}:{uuid}).
// Only protects against provider-internal retries within the single attempt.
// App-level retries (user double-clicks "submit") will pass a NEW UUID
// each time, so the provider can't dedupe them.
$user->useFeature('ai-tokens', 100);
```

Pass a stable token whenever the caller has a meaningful operation ID. The auto-generated UUID is a safe default but doesn't protect against duplicate logical calls.

### Operational concerns

**Orphan charges.** The provider charge happens *before* the DB transaction that records the consume. If the inner transaction fails (DB unreachable, log write conflict, event store error) after the provider has already debited the balance, the result is an "orphan charge" — money moved on the provider side, no record on Tashil's side.

When this happens:

1. `Log::critical()` fires with `{idempotency_key, subscription_id, feature_id, feature_slug, units, unit_price, amount, currency, exception}` so an operator can detect and reconcile.
2. The exception is re-thrown so the caller knows the consume didn't complete cleanly.

Operators reconciling orphan charges:

- The `idempotency_key` in the log matches the key the provider stored against its charge record. Query the provider for charges whose key isn't present in `tashil_usage_logs.metadata->idempotency_key` to find orphans.
- For each orphan, either retry the consume idempotently (provider should return success without re-charging) or refund the original charge on the provider side, depending on host policy.

True cross-system atomicity isn't possible without 2-phase commit support from the provider. The charge-then-write order is chosen because the alternative (write-then-charge) is worse — silently advancing the counter without taking the money is a revenue loss with no audit trail.

### Edge cases

- **`reportStorage()` is rejected** for metered features — the absolute-set model doesn't make sense for delta-charging.
- **`check()` delegates to `hasSufficientBalance`** for metered — useful for UI gating, but inherently TOCTOU; `useFeature()` is the authoritative gate.
- **`UsageLimitWarning` does not fire** for metered (no `limit_value`). Listen for `MeteredCharged` to surface spend.
- **Trial subscriptions still charge.** Tashil makes no policy decision here — if you want free trial metering, return `true` from `charge()` without debiting when `$subscription->isOnTrial()`.
- **Multi-currency:** the charge currency is `subscription.package.currency`. A subscriber on two subscriptions in different currencies will have each charge against the matching currency.

## Resetting

Manual:

```php
app('tashil')->usage()->resetUsage($sub, 'api-requests');
app('tashil')->usage()->resetAllUsage($sub);
```

Scheduled (recommended): `tashil:reset-quotas`. See [Scheduler Jobs](04-Scheduler-Jobs.md).

## Querying current state

| Need | Code |
|---|---|
| Current snapshot value | `$user->featureValue('api-requests')` |
| Current usage (float) | `$user->featureUsage('api-requests')` |
| Remaining (`null` = unlimited) | `$user->featureRemaining('api-requests')` |
| Historical daily usage | `$user->dailyUsageFor('api-requests', 30)` |
| Snapshot rows current | `$sub->currentFeatures` |
| Snapshot rows historical | `$sub->subscriptionFeatures` |

## Point-in-time

```php
app(\Foysal50x\Tashil\Contracts\SubscriptionFeatureRepositoryInterface::class)
    ->asOf($sub, now()->subDays(30));
```

Returns the snapshot rows in effect at that moment — `added_at ≤ moment AND (superseded_at IS NULL OR superseded_at > moment)`.
