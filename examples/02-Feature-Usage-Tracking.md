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
