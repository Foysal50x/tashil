<?php

use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Services\AnalyticsService;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $this->package = Package::create([
        'name'             => 'Pro Monthly',
        'slug'             => 'pro-monthly',
        'price'            => 30.00,
        'currency'         => 'USD',
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    $this->analytics = app(AnalyticsService::class);
});

it('returns complete dashboard summary with all KPIs', function () {
    $user1 = createUser();
    $user2 = createUser();
    $user3 = createUser();

    // Active subscription
    $sub1 = Subscription::create([
        'subscriber_type' => get_class($user1),
        'subscriber_id'   => $user1->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    // On trial subscription
    Subscription::create([
        'subscriber_type' => get_class($user2),
        'subscriber_id'   => $user2->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::OnTrial,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
        'trial_ends_at'   => now()->addDays(14),
    ]);

    // Cancelled subscription
    Subscription::create([
        'subscriber_type' => get_class($user3),
        'subscriber_id'   => $user3->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Cancelled,
        'starts_at'       => now()->subMonth(),
        'ends_at'         => now(),
        'cancelled_at'    => now(),
    ]);

    // Invoices
    Invoice::create([
        'subscription_id' => $sub1->id,
        'invoice_number'  => 'INV-001',
        'amount'          => 30.00,
        'currency'        => 'USD',
        'status'          => InvoiceStatus::Paid,
        'issued_at'       => now(),
        'due_date'        => now()->addDays(30),
        'paid_at'         => now(),
    ]);

    Invoice::create([
        'subscription_id' => $sub1->id,
        'invoice_number'  => 'INV-002',
        'amount'          => 30.00,
        'currency'        => 'USD',
        'status'          => InvoiceStatus::Pending,
        'issued_at'       => now(),
        'due_date'        => now()->addDays(30),
    ]);

    $summary = $this->analytics->dashboardSummary();

    expect($summary)->toHaveKeys([
        'total_subscriptions',
        'active_subscriptions',
        'subscriptions_by_status',
        'mrr',
        'arpu',
        'total_revenue',
        'churn_rate',
        'trial_conversion_rate',
        'pending_invoices',
        'overdue_invoices',
    ]);

    expect($summary['total_subscriptions'])->toBe(3);
    expect($summary['active_subscriptions'])->toBe(2); // active + on_trial
    // dashboardStats() counts 'active' as active + on_trial combined
    expect($summary['subscriptions_by_status']['active'])->toBe(2);
    expect($summary['subscriptions_by_status']['on_trial'])->toBe(1);
    expect($summary['subscriptions_by_status']['cancelled'])->toBe(1);
    expect($summary['mrr'])->toBeGreaterThan(0);
    expect($summary['total_revenue'])->toBe(30.00);
    expect($summary['pending_invoices'])->toBe(1);
    expect($summary['overdue_invoices'])->toBe(0);
});

it('returns zero ARPU when no active subscriptions in dashboard', function () {
    $user = createUser();

    Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Cancelled,
        'starts_at'       => now()->subMonth(),
        'ends_at'         => now(),
        'cancelled_at'    => now(),
    ]);

    $summary = $this->analytics->dashboardSummary();

    expect($summary['arpu'])->toBe(0.0);
    expect($summary['mrr'])->toBe(0.0);
});

it('calculates churn rate correctly in dashboard', function () {
    $user1 = createUser();
    $user2 = createUser();

    Subscription::create([
        'subscriber_type' => get_class($user1),
        'subscriber_id'   => $user1->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    Subscription::create([
        'subscriber_type' => get_class($user2),
        'subscriber_id'   => $user2->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Cancelled,
        'starts_at'       => now()->subMonth(),
        'ends_at'         => now(),
        'cancelled_at'    => now(),
    ]);

    $summary = $this->analytics->dashboardSummary();

    // churn_rate = cancelled / (total - expired) * 100
    // = 1 / (2 - 0) * 100 = 50%
    expect($summary['churn_rate'])->toBe(50.00);
});

it('returns dashboard summary with overdue invoices', function () {
    $user = createUser();

    $subscription = Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    // Overdue invoice
    Invoice::create([
        'subscription_id' => $subscription->id,
        'invoice_number'  => 'INV-001',
        'amount'          => 30.00,
        'currency'        => 'USD',
        'status'          => InvoiceStatus::Pending,
        'issued_at'       => now()->subDays(60),
        'due_date'        => now()->subDays(30),
    ]);

    $summary = $this->analytics->dashboardSummary();

    expect($summary['pending_invoices'])->toBe(1);
    expect($summary['overdue_invoices'])->toBe(1);
});

it('returns empty dashboard summary when no data', function () {
    $summary = $this->analytics->dashboardSummary();

    expect($summary['total_subscriptions'])->toBe(0);
    expect($summary['active_subscriptions'])->toBe(0);
    expect($summary['mrr'])->toBe(0.0);
    expect($summary['arpu'])->toBe(0.0);
    expect($summary['total_revenue'])->toBe(0.0);
    expect($summary['churn_rate'])->toBe(0.0);
    expect($summary['pending_invoices'])->toBe(0);
    expect($summary['overdue_invoices'])->toBe(0);
});
