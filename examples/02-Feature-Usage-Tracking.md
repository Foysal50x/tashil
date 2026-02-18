# Feature Usage Tracking

Track usage of features (Limits) or check access to features (Booleans).

## 1. Defining Features

Features are defined in the `tashil_features` table and linked to packages.

```php
use Foysal50x\Tashil\Models\Feature;

// Limit Feature (e.g., 1000 API calls/month)
$apiFeature = Feature::create([
    'name' => 'API Requests',
    'slug' => 'api-requests',
    'type' => 'limit',
]);

// Boolean Feature (e.g., Access to detailed reports)
$reportFeature = Feature::create([
    'name' => 'Detailed Reports',
    'slug' => 'detailed-reports',
    'type' => 'boolean',
]);

// Attach to Package
$package->features()->attach($apiFeature, ['value' => '1000']);
$package->features()->attach($reportFeature, ['value' => 'true']);
```

## 2. Checking Access

Check if a user's subscription allows access to a feature.

```php
$subscription = $user->subscription;

// Check boolean feature
if (app('tashil')->usage()->check($subscription, 'detailed-reports')) {
    // access granted
}

// Check limit feature (returns true if usage < limit)
if (app('tashil')->usage()->check($subscription, 'api-requests')) {
    // proceed with request
}
```

## 3. Incrementing Usage

Increment usage for limit-based features.

```php
// Increment by 1
app('tashil')->usage()->increment($subscription, 'api-requests');

// Increment by specific amount
app('tashil')->usage()->increment($subscription, 'ai-tokens', 150);
```

This will automatically:

1. Check if the increment exceeds the limit.
2. Log the usage in `tashil_usage_logs`.
3. Dispatch `UsageLimitWarning` event if usage crosses 80%.

## 4. Resetting Usage

Usage can be reset manually or via scheduled command (implied by period).

```php
// Reset specific feature
app('tashil')->usage()->resetUsage($subscription, 'api-requests');

// Reset ALL features for a subscription
app('tashil')->usage()->resetAllUsage($subscription);
```
