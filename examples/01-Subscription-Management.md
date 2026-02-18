# Subscription Management

This guide covers how to manage user subscriptions using the `tahsil` package.

## 1. Subscribe a User

To subscribe a user (or any model using the `HasSubscriptions` trait) to a package:

```php
use Foysal50x\Tashil\Models\Package;
use App\Models\User;

$user = User::find(1);
$package = Package::where('slug', 'pro-monthly')->first();

// Subscribe
$subscription = app('tashil')->subscription()->subscribe($user, $package);

// Subscribe with Trial (if package supports it)
$subscription = app('tashil')->subscription()->subscribe($user, $package, withTrial: true);
```

## 2. Check Subscription Status

You can check if a user has an active subscription:

```php
if ($user->onPlan('pro-monthly')) {
    // User is on the Pro Monthly plan
}

if ($user->subscribed()) {
    // User has ANY active subscription
}
```

## 3. Cancel a Subscription

Cancel a subscription either immediately or at the end of the billing cycle.

```php
$subscription = $user->subscription;

// Cancel at end of period (default)
app('tashil')->subscription()->cancel($subscription);

// Cancel immediately
app('tashil')->subscription()->cancel($subscription, immediate: true, reason: 'User request');
```

## 4. Resume a Subscription

Resume a cancelled subscription if it's within the grace period (before it fully expires).

```php
$subscription = $user->subscription;

if ($subscription->onGracePeriod()) {
    app('tashil')->subscription()->resume($subscription);
}
```

## 5. Switch Plans

Upgrade or downgrade a user's subscription. The old subscription is cancelled, and a new one is created.

```php
$newPackage = Package::where('slug', 'enterprise-yearly')->first();

$newSubscription = app('tashil')->subscription()->switchPlan($subscription, $newPackage);
```
