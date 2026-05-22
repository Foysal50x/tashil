# Feature System

Tahsil separates **what features exist** (catalog), **what features a plan offers** (configuration), **what a subscription was given** (immutable snapshot), and **what the subscription has used** (mutable counter). Each concern lives in its own table.

## Four feature types

| Type | Enum | Behavior |
|---|---|---|
| Boolean | `FeatureType::Boolean` | Pure on/off. `value` stores `"true"`/`"false"`. No counter needed. |
| Limit | `FeatureType::Limit` | Numeric quota with hard enforcement. Counter increments atomically; over-limit increments are rejected. |
| Consumable | `FeatureType::Consumable` | Tracked usage without a hard ceiling (or with one — the counter still records). Useful for soft-limit metering. |
| Enum | `FeatureType::Enum` | Named option (e.g. tier label). Read via `featureValue()`; no counter semantics. |

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
2. One row in `tashil_feature_usages` (the counter — `usage=0`, `limit_value` parsed from the pivot value if numeric, `period_start=now`, `period_end=now + first window`).

It also appends `subscription.created` to the event store.

If the plan changes later (`switchPlan` / `applyPendingChange`), the old snapshot rows are stamped `superseded_at = now()` and new snapshot rows are inserted with `superseded_at = null`. Both sets stay in the table forever — the snapshot is the audit trail of "what features did this subscription have at time T?".

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
