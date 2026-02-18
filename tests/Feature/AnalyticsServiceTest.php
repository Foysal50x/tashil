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

// ── Subscription Count Metrics ──────────────────────────────────────

it('returns total subscription count', function () {
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

    expect($this->analytics->totalSubscriptionCount())->toBe(2);
});

it('returns active subscription count', function () {
    $user1 = createUser();
    $user2 = createUser();
    $user3 = createUser();

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
        'status'          => SubscriptionStatus::OnTrial,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
        'trial_ends_at'   => now()->addDays(14),
    ]);

    Subscription::create([
        'subscriber_type' => get_class($user3),
        'subscriber_id'   => $user3->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Cancelled,
        'starts_at'       => now()->subMonth(),
        'ends_at'         => now(),
        'cancelled_at'    => now(),
    ]);

    // Active count = active + on_trial = 2
    expect($this->analytics->activeSubscriptionCount())->toBe(2);
});

it('returns subscription count by status', function () {
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
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    $result = $this->analytics->subscriptionCountByStatus();

    expect($result)->toBeArray();
    expect($result[SubscriptionStatus::Active->value])->toBe(2);
});

// ── Subscribers by Package ──────────────────────────────────────────

it('returns subscribers grouped by package', function () {
    $user = createUser();

    Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    $result = $this->analytics->subscribersByPackage();

    expect($result)->toHaveCount(1);
    expect($result[0]['package_id'])->toBe($this->package->id);
    expect($result[0]['package_name'])->toBe('Pro Monthly');
    expect($result[0]['count'])->toBe(1);
});

// ── Trial Conversion Rate ───────────────────────────────────────────

it('returns trial conversion rate', function () {
    $user1 = createUser();
    $user2 = createUser();

    // Converted trial
    Subscription::create([
        'subscriber_type' => get_class($user1),
        'subscriber_id'   => $user1->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now()->subMonth(),
        'ends_at'         => now()->addMonth(),
        'trial_ends_at'   => now()->subDays(7),
    ]);

    // Still on trial
    Subscription::create([
        'subscriber_type' => get_class($user2),
        'subscriber_id'   => $user2->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::OnTrial,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
        'trial_ends_at'   => now()->addDays(7),
    ]);

    // 1 converted / 2 total trials = 50%
    expect($this->analytics->trialConversionRate())->toBe(50.00);
});

it('returns zero trial conversion rate when no trials exist', function () {
    $user = createUser();

    Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    expect($this->analytics->trialConversionRate())->toBe(0.0);
});

// ── Subscription Growth ─────────────────────────────────────────────

it('returns subscription growth per period', function () {
    $user = createUser();

    Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    $result = $this->analytics->subscriptionGrowth(1);

    expect($result)->toBeArray()->not->toBeEmpty();
    expect($result[0])->toHaveKeys(['month', 'count']);
    expect($result[0]['count'])->toBeGreaterThanOrEqual(1);
});

// ── Revenue Metrics ─────────────────────────────────────────────────

it('calculates MRR for monthly subscriptions', function () {
    $user = createUser();

    Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    expect($this->analytics->calculateMRR())->toBe(30.00);
});

it('calculates ARPU correctly', function () {
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
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    // MRR = 60, active = 2, ARPU = 30
    expect($this->analytics->averageRevenuePerUser())->toBe(30.00);
});

it('returns zero ARPU when no active subscriptions', function () {
    expect($this->analytics->averageRevenuePerUser())->toBe(0.0);
});

it('returns total revenue from paid invoices', function () {
    $user = createUser();

    $subscription = Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    Invoice::create([
        'subscription_id' => $subscription->id,
        'invoice_number'  => 'INV-001',
        'amount'          => 30.00,
        'currency'        => 'USD',
        'status'          => InvoiceStatus::Paid,
        'issued_at'       => now(),
        'due_date'        => now()->addDays(30),
        'paid_at'         => now(),
    ]);

    Invoice::create([
        'subscription_id' => $subscription->id,
        'invoice_number'  => 'INV-002',
        'amount'          => 30.00,
        'currency'        => 'USD',
        'status'          => InvoiceStatus::Pending,
        'issued_at'       => now(),
        'due_date'        => now()->addDays(30),
    ]);

    // Only paid invoice counts
    expect($this->analytics->totalRevenue())->toBe(30.00);
});

it('returns revenue by period', function () {
    $user = createUser();

    $subscription = Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    Invoice::create([
        'subscription_id' => $subscription->id,
        'invoice_number'  => 'INV-001',
        'amount'          => 30.00,
        'currency'        => 'USD',
        'status'          => InvoiceStatus::Paid,
        'issued_at'       => now(),
        'due_date'        => now()->addDays(30),
        'paid_at'         => now(),
    ]);

    $result = $this->analytics->revenueByPeriod(1);

    expect($result)->toBeArray()->not->toBeEmpty();
    expect($result[0])->toHaveKeys(['month', 'revenue']);
    expect($result[0]['revenue'])->toBe(30.00);
});

it('returns revenue by package', function () {
    $user = createUser();

    $subscription = Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    Invoice::create([
        'subscription_id' => $subscription->id,
        'invoice_number'  => 'INV-001',
        'amount'          => 30.00,
        'currency'        => 'USD',
        'status'          => InvoiceStatus::Paid,
        'issued_at'       => now(),
        'due_date'        => now()->addDays(30),
        'paid_at'         => now(),
    ]);

    $result = $this->analytics->revenueByPackage();

    expect($result)->toHaveCount(1);
    expect($result[0]['package_id'])->toBe($this->package->id);
    expect($result[0]['revenue'])->toBe(30.00);
});

// ── Invoice Metrics ─────────────────────────────────────────────────

it('returns pending invoice count', function () {
    $user = createUser();

    $subscription = Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    Invoice::create([
        'subscription_id' => $subscription->id,
        'invoice_number'  => 'INV-001',
        'amount'          => 30.00,
        'currency'        => 'USD',
        'status'          => InvoiceStatus::Pending,
        'issued_at'       => now(),
        'due_date'        => now()->addDays(30),
    ]);

    Invoice::create([
        'subscription_id' => $subscription->id,
        'invoice_number'  => 'INV-002',
        'amount'          => 30.00,
        'currency'        => 'USD',
        'status'          => InvoiceStatus::Paid,
        'issued_at'       => now(),
        'paid_at'         => now(),
    ]);

    expect($this->analytics->pendingInvoiceCount())->toBe(1);
});

it('returns overdue invoice count', function () {
    $user = createUser();

    $subscription = Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    // Overdue: pending + past due_date
    Invoice::create([
        'subscription_id' => $subscription->id,
        'invoice_number'  => 'INV-001',
        'amount'          => 30.00,
        'currency'        => 'USD',
        'status'          => InvoiceStatus::Pending,
        'issued_at'       => now()->subDays(60),
        'due_date'        => now()->subDays(30),
    ]);

    // Pending but not overdue
    Invoice::create([
        'subscription_id' => $subscription->id,
        'invoice_number'  => 'INV-002',
        'amount'          => 30.00,
        'currency'        => 'USD',
        'status'          => InvoiceStatus::Pending,
        'issued_at'       => now(),
        'due_date'        => now()->addDays(30),
    ]);

    expect($this->analytics->overdueInvoiceCount())->toBe(1);
});

// ── Churn Metrics ───────────────────────────────────────────────────

it('calculates churn rate', function () {
    $user1 = createUser();
    $user2 = createUser();

    Subscription::create([
        'subscriber_type' => get_class($user1),
        'subscriber_id'   => $user1->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now()->subDays(15),
        'ends_at'         => now()->addMonth(),
    ]);

    Subscription::create([
        'subscriber_type' => get_class($user2),
        'subscriber_id'   => $user2->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Cancelled,
        'starts_at'       => now()->subDays(15),
        'ends_at'         => now()->addDays(15),
        'cancelled_at'    => now()->subDays(5),
    ]);

    $churn = $this->analytics->churnRate(30);

    // 1 churned / 2 total = 50%
    expect($churn)->toBe(50.00);
});

it('returns zero churn rate when no subscriptions exist', function () {
    expect($this->analytics->churnRate())->toBe(0.0);
});

it('returns churn trend data', function () {
    $user = createUser();

    Subscription::create([
        'subscriber_type' => get_class($user),
        'subscriber_id'   => $user->id,
        'package_id'      => $this->package->id,
        'status'          => SubscriptionStatus::Active,
        'starts_at'       => now(),
        'ends_at'         => now()->addMonth(),
    ]);

    $result = $this->analytics->churnTrend(3);

    expect($result)->toBeArray()->toHaveCount(3);
    expect($result[0])->toHaveKeys(['month', 'churn_rate']);
});

// ── Daily Usage ─────────────────────────────────────────────────────

// Note: getDailyUsage() depends on Tpetry\QueryExpressions\Function\DTime\Date
// which is not available in the current version of tpetry/laravel-query-expressions.
// Skipping this test until the dependency is updated.
