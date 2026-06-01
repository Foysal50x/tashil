# Feature Usage Tracking

Track usage of feature counters or check access to boolean/consumable features.

## 1. Define features

```php
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Enums\ResetPeriod;

$apiFeature = Feature::create([
    'name'         => 'API Requests',
    'slug'         => 'api-requests',
    'type'         => 'limit',
    'reset_period' => ResetPeriod::Monthly, // resets at every period_end
]);

$reportFeature = Feature::create([
    'name' => 'Detailed Reports',
    'slug' => 'detailed-reports',
    'type' => 'boolean',
]);

$package->features()->attach($apiFeature, ['value' => '1000']);
$package->features()->attach($reportFeature, ['value' => 'true']);
```

On subscribe, tahsil writes **two** rows per feature:

- An immutable **snapshot** (`tashil_subscription_features`) — the feature config frozen at subscription time, retained forever for audit.
- A mutable **counter** (`tashil_feature_usages`) — current usage, limit cached for atomic updates, period window for reset scheduling.

If the plan changes later, old snapshot rows are stamped with `superseded_at` and new snapshots are added — the history is preserved.

## 2. Check access

```php
$subscription = $user->subscription;

if (app('tashil')->usage()->check($subscription, 'detailed-reports')) {
    // boolean — true means snapshot says true AND feature is still active
}

if (app('tashil')->usage()->check($subscription, 'api-requests')) {
    // limit — true means within remaining quota
}
```

Access is denied automatically when:

- The subscription isn't valid (`isValid()` returns false — covers expired, cancelled, paused, suspended).
- The catalog `Feature.is_active` is false (so admins can disable a feature globally without per-subscription cleanup).
- The counter has reached its limit.

## 3. Increment usage atomically

```php
app('tashil')->usage()->increment($subscription, 'api-requests');           // +1
app('tashil')->usage()->increment($subscription, 'ai-tokens', 150);          // +150
```

The increment is a single conditional `UPDATE` — two concurrent callers cannot both succeed past the limit. The method returns `false` when the limit would be exceeded; the counter is untouched in that case.

Each successful increment writes a row to `tashil_usage_logs` with `previous_usage` + `new_usage` so the history is fully reconstructable.

`UsageLimitWarning` fires exactly once per period when the counter crosses 80% of the limit (not on every increment past 80%).

## 4. Absolute reporting (storage-style features)

```php
app('tashil')->usage()->reportStorage($subscription, 'storage-gb', 250.0);
```

Use when the caller computes the absolute usage rather than a delta (e.g. measuring bucket size). The 80%-threshold event fires on crossings, not on every report.

## 5. Reset

```php
app('tashil')->usage()->resetUsage($subscription, 'api-requests');
app('tashil')->usage()->resetAllUsage($subscription);
```

Scheduled resets: `tashil:reset-quotas` runs daily, zeroes counters whose `period_end` has elapsed, advances `period_start`/`period_end` anchored to the previous `period_end` (no drift if cron is late), and appends `usage.reset` to the event log.

## 6. Read the counter

```php
$user->featureUsage('api-requests');     // float — current usage
$user->featureRemaining('api-requests'); // float|null — null means unlimited
$user->featureValue('api-requests');     // string|null — the snapshot value
$user->dailyUsageFor('api-requests', 30); // daily aggregates from usage_logs
```

## 7. Metered features — charged per unit consumed

Metered features deduct `units × unit_price` from the subscriber's balance on every consume. Tashil never owns the balance — the host implements `MeteredBilling`.

### Define

```php
$aiTokens = app('tashil')->feature('ai-tokens')
    ->name('AI Tokens')
    ->metered()
    ->create();

// Pivot value = unit price (decimal string) in the package's currency.
$package->features()->attach($aiTokens, ['value' => '0.001']); // $0.001 per token
```

### Implement MeteredBilling — pick the pattern that fits your codebase

Tashil supports two patterns and decides per consume which one to use. No global flag — `UsageService` checks `$subscriber instanceof MeteredBilling` first, and only falls back to the container binding if not.

#### Pattern A — self-implement on the Subscribable model

Best when the subscriber model itself owns the wallet/balance.

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
        return $this->wallets()->forCurrency($currency)->debit(
            $amount,
            idempotencyKey: $context['idempotency_key'],
            meta: $context,
        );
    }
}
```

No binding needed — Tashil discovers the implementation through the model itself.

#### Pattern B — standalone class bound via the container

Best when billing lives in its own service layer or is shared across multiple subscriber types.

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

Both patterns get the same `$context` (with `idempotency_key`, `subscription_id`, `feature_id`, `feature_slug`, `units`, `unit_price`) and trigger the same `MeteredCharged` / `MeteredChargeRejected` events.

### Consume

```php
// Charges 100 × 0.001 = 0.10 USD via the provider before advancing the counter.
// Returns false on provider rejection (insufficient balance, gateway error).
$ok = $user->useFeature('ai-tokens', 100);

if (! $ok) {
    // listen for MeteredChargeRejected to drive a top-up prompt
}
```

What you get on success:

- The counter advances by `units` (analytics).
- One `usage_logs` row with `operation=consume` plus `metadata.amount`, `metadata.unit_price`, `metadata.currency`.
- One `subscription_events` row (`event_type='usage.metered_charged'`).
- `MeteredCharged` event after DB commit.

On rejection: nothing is written, `MeteredChargeRejected` event fires, `useFeature()` returns `false`.

Without a bound `MeteredBilling`, the default `NullMeteredBilling` throws `MeteredBillingNotConfiguredException` so misconfiguration fails loud.

See [docs/02-Feature-System.md › Metered features](../docs/02-Feature-System.md#metered-features) for the idempotency contract and edge cases (trial charging policy, multi-currency, `reportStorage` refusal).
