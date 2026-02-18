<?php

use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Services\AnalyticsService;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $this->monthlyPackage = Package::create([
        'name'             => 'Pro Monthly',
        'slug'             => 'pro-monthly',
        'price'            => 29.99,
        'currency'         => 'USD',
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    $this->yearlyPackage = Package::create([
        'name'             => 'Enterprise Yearly',
        'slug'             => 'enterprise-yearly',
        'price'            => 299.99,
        'currency'         => 'USD',
        'billing_period'   => 'year',
        'billing_interval' => 1,
    ]);

    $this->analytics = app(AnalyticsService::class);
});

// ── packageAnalytics() ──────────────────────────────────────────────

it('returns per-package analytics for multiple packages', function () {
    // Create subscriptions for the monthly package
    $user1 = createUser();
    $user2 = createUser();
    $user3 = createUser();

    Subscription::create([
        'subscriber_type' => get_class($user1),
        'subscriber_id'   => $user1->id,
        'package_id'      => $this->monthlyPackage->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    Subscription::create([
        'subscriber_type' => get_class($user2),
        'subscriber_id'   => $user2->id,
        'package_id'      => $this->monthlyPackage->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    Subscription::create([
        'subscriber_type' => get_class($user3),
        'subscriber_id'   => $user3->id,
        'package_id'      => $this->monthlyPackage->id,
        'status'          => SubscriptionStatus::Cancelled,
        'starts_at'       => now()->subMonth(),
        'ends_at'         => now(),
        'cancelled_at'    => now(),
    ]);

    // Create subscription for the yearly package
    $user4 = createUser();
    Subscription::create([
        'subscriber_type' => get_class($user4),
        'subscriber_id'   => $user4->id,
        'package_id'      => $this->yearlyPackage->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addYear(),
    ]);

    $result = $this->analytics->packageAnalytics();

    expect($result)->toBeArray()->toHaveCount(2);

    // Find the monthly package result
    $monthly = collect($result)->firstWhere('package_id', $this->monthlyPackage->id);
    expect($monthly)->not->toBeNull();
    expect($monthly['package_name'])->toBe('Pro Monthly');
    expect($monthly['total_subscribers'])->toBe(3);
    expect($monthly['active_subscribers'])->toBe(2);
    expect($monthly['cancelled_count'])->toBe(1);
    expect($monthly['mrr'])->toBeGreaterThan(0);

    // Find the yearly package result
    $yearly = collect($result)->firstWhere('package_id', $this->yearlyPackage->id);
    expect($yearly)->not->toBeNull();
    expect($yearly['package_name'])->toBe('Enterprise Yearly');
    expect($yearly['total_subscribers'])->toBe(1);
    expect($yearly['active_subscribers'])->toBe(1);
    expect($yearly['cancelled_count'])->toBe(0);
});

it('calculates correct MRR per package', function () {
    $user = createUser();
    Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->monthlyPackage->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    $user2 = createUser();
    Subscription::create([
        'subscriber_type' => get_class($user2),
        'subscriber_id'   => $user2->id,
        'package_id'      => $this->yearlyPackage->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addYear(),
    ]);

    $result = $this->analytics->packageAnalytics();

    $monthly = collect($result)->firstWhere('package_id', $this->monthlyPackage->id);
    // Monthly package: $29.99 / 1 month = $29.99 MRR
    expect($monthly['mrr'])->toBe(29.99);
    expect($monthly['average_mrr'])->toBe(29.99);

    $yearly = collect($result)->firstWhere('package_id', $this->yearlyPackage->id);
    // Yearly package: $299.99 / 12 months ≈ $25.00
    expect($yearly['mrr'])->toBe(round(299.99 / 12, 2));
    expect($yearly['average_mrr'])->toBe(round(299.99 / 12, 2));
});

it('calculates average MRR correctly with multiple active subscribers', function () {
    $user1 = createUser();
    $user2 = createUser();

    Subscription::create([
        'subscriber_type' => get_class($user1),
        'subscriber_id'   => $user1->id,
        'package_id'      => $this->monthlyPackage->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    Subscription::create([
        'subscriber_type' => get_class($user2),
        'subscriber_id'   => $user2->id,
        'package_id'      => $this->monthlyPackage->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    $result = $this->analytics->packageAnalytics();

    $monthly = collect($result)->firstWhere('package_id', $this->monthlyPackage->id);
    // 2 active subscribers at $29.99/month = $59.98 MRR total
    expect($monthly['mrr'])->toBe(59.98);
    // Average MRR = 59.98 / 2 = 29.99
    expect($monthly['average_mrr'])->toBe(29.99);
});

it('includes invoice counts per package', function () {
    $user = createUser();
    $subscription = Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->monthlyPackage->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    // Create paid invoice
    Invoice::create([
        'subscription_id' => $subscription->id,
        'invoice_number'  => 'INV-001',
        'amount'          => 29.99,
        'currency'        => 'USD',
        'status'          => InvoiceStatus::Paid,
        'issued_at'       => now(),
        'due_date'        => now()->addDays(30),
        'paid_at'         => now(),
    ]);

    // Create pending invoice (not overdue)
    Invoice::create([
        'subscription_id' => $subscription->id,
        'invoice_number'  => 'INV-002',
        'amount'          => 29.99,
        'currency'        => 'USD',
        'status'          => InvoiceStatus::Pending,
        'issued_at'       => now(),
        'due_date'        => now()->addDays(30),
    ]);

    // Create overdue invoice (pending + past due date)
    Invoice::create([
        'subscription_id' => $subscription->id,
        'invoice_number'  => 'INV-003',
        'amount'          => 29.99,
        'currency'        => 'USD',
        'status'          => InvoiceStatus::Pending,
        'issued_at'       => now()->subDays(60),
        'due_date'        => now()->subDays(30),
    ]);

    $result = $this->analytics->packageAnalytics();

    $monthly = collect($result)->firstWhere('package_id', $this->monthlyPackage->id);
    expect($monthly['pending_invoices'])->toBe(2);   // 2 pending invoices
    expect($monthly['overdue_invoices'])->toBe(1);    // 1 overdue invoice
    expect($monthly['total_revenue'])->toBe(29.99);   // only paid invoice revenue
});

it('returns zero values for packages with no active subscribers', function () {
    $user = createUser();
    Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->monthlyPackage->id,
        'status'          => SubscriptionStatus::Cancelled,
        'starts_at'       => now()->subMonth(),
        'ends_at'         => now(),
        'cancelled_at'    => now(),
    ]);

    $result = $this->analytics->packageAnalytics();

    $monthly = collect($result)->firstWhere('package_id', $this->monthlyPackage->id);
    expect($monthly['total_subscribers'])->toBe(1);
    expect($monthly['active_subscribers'])->toBe(0);
    expect($monthly['mrr'])->toBe(0.0);
    expect($monthly['average_mrr'])->toBe(0.0);
    expect($monthly['cancelled_count'])->toBe(1);
});

it('calculates trial conversion rate per package', function () {
    // 2 users started a trial, only 1 converted to active
    $user1 = createUser();
    $user2 = createUser();

    // Trial that converted to active
    Subscription::create([
        'subscriber_type' => get_class($user1),
        'subscriber_id'   => $user1->id,
        'package_id'      => $this->monthlyPackage->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now()->subMonth(),
        'ends_at'         => now()->addMonth(),
        'trial_ends_at'   => now()->subDays(7),
    ]);

    // Trial that is still on trial
    Subscription::create([
        'subscriber_type' => get_class($user2),
        'subscriber_id'   => $user2->id,
        'package_id'      => $this->monthlyPackage->id,
        'status'          => SubscriptionStatus::OnTrial,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
        'trial_ends_at'   => now()->addDays(7),
    ]);

    $result = $this->analytics->packageAnalytics();

    $monthly = collect($result)->firstWhere('package_id', $this->monthlyPackage->id);
    // 1 converted out of 2 trials = 50%
    expect($monthly['trial_conversion_rate'])->toBe(50.00);
});

it('returns empty array when no subscriptions exist', function () {
    $result = $this->analytics->packageAnalytics();

    expect($result)->toBeArray()->toBeEmpty();
});

it('returns zero pending and overdue invoices when package has no invoices', function () {
    $user = createUser();
    Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->monthlyPackage->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    $result = $this->analytics->packageAnalytics();

    $monthly = collect($result)->firstWhere('package_id', $this->monthlyPackage->id);
    expect($monthly['pending_invoices'])->toBe(0);
    expect($monthly['overdue_invoices'])->toBe(0);
});
