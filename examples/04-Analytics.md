# Analytics

Retrieve insights about subscriptions and revenue.

## 1. Dashboard Stats

Get high-level metrics for an admin dashboard.

```php
$stats = app('tashil')->analytics()->dashboardStats();

/*
Returns:
[
    'total_active_subscriptions' => 150,
    'mrr' => 4500.00,
    'churn_rate' => 2.5, // percent
    'new_subscriptions_this_month' => 15,
]
*/
```

## 2. Package-Level Analytics

Get detailed stats broken down by package.

```php
$packageStats = app('tashil')->analytics()->packageAnalytics();

/*
Returns an array of packages with:
- total_subscribers
- active_subscribers
- mrr (Monthly Recurring Revenue)
- average_mrr
- trial_conversion_rate
- pending_invoices
- overdue_invoices
*/
```
