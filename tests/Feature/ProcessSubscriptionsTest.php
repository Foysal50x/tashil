<?php

use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Support\Carbon;

it('creates invoices for auto renewing subscriptions expiring today', function () {
    Carbon::setTestNow('2023-01-01 00:00:00');

    $package = Package::factory()->create(['price' => 100]);
    $subscription = Subscription::factory()->create([
        'package_id' => $package->id,
        'ends_at' => Carbon::today(),
        'auto_renew' => true,
        'status' => SubscriptionStatus::Active,
    ]);

    $this->artisan('tashil:process-subscriptions')
        ->assertSuccessful();

    $invoiceTable = (new Invoice)->getTable();

    $this->assertDatabaseHas($invoiceTable, [
        'subscription_id' => $subscription->id,
        'amount' => 100,
        'status' => InvoiceStatus::Pending,
    ]);
    
    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active);
});

it('cancels auto renewing subscription if pending invoice exists', function () {
    Carbon::setTestNow('2023-01-01 00:00:00');

    $package = Package::factory()->create(['price' => 100]);
    $subscription = Subscription::factory()->create([
        'package_id' => $package->id,
        'ends_at' => Carbon::today(),
        'auto_renew' => true,
        'status' => SubscriptionStatus::Active,
    ]);

    // Create an existing pending invoice
    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'status' => InvoiceStatus::Pending,
    ]);

    $this->artisan('tashil:process-subscriptions')
        ->assertSuccessful();

    // Should NOT create a new invoice (count was 1, should stay 1)
    expect($subscription->invoices)->toHaveCount(1);

    // Subscription should be cancelled
    $subscription->refresh();
    // Depending on logic, it might be Cancelled or Expired. The code sets Cancelled.
    expect($subscription->status === SubscriptionStatus::Cancelled || $subscription->cancelled_at !== null)->toBeTrue();
});

it('expires subscriptions that do not auto renew', function () {
    Carbon::setTestNow('2023-01-01 00:00:00');

    $subscription = Subscription::factory()->create([
        'ends_at' => Carbon::today(),
        'auto_renew' => false,
        'status' => SubscriptionStatus::Active,
    ]);

    $this->artisan('tashil:process-subscriptions')
        ->assertSuccessful();

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired);
});

it('fetches all expiring subscriptions when auto renew is null', function () {
    Carbon::setTestNow('2023-01-01 00:00:00');

    $package = Package::factory()->create();
    
    // Auto-renewing subscription
    Subscription::factory()->create([
        'package_id' => $package->id,
        'ends_at' => Carbon::today(),
        'auto_renew' => true,
        'status' => SubscriptionStatus::Active,
    ]);

    // Non-auto-renewing subscription
    Subscription::factory()->create([
        'package_id' => $package->id,
        'ends_at' => Carbon::today(),
        'auto_renew' => false,
        'status' => SubscriptionStatus::Active,
    ]);

    // Subscription expiring tomorrow (should not be fetched)
    Subscription::factory()->create([
        'package_id' => $package->id,
        'ends_at' => Carbon::tomorrow(),
        'auto_renew' => true,
        'status' => SubscriptionStatus::Active,
    ]);

    $repo = app(\Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface::class);
    $expiring = $repo->getExpiringSubscriptions(Carbon::today(), null);

    expect($expiring)->toHaveCount(2);
});

it('does not process subscriptions expiring tomorrow', function () {
    Carbon::setTestNow('2023-01-01 00:00:00');

    $subscription = Subscription::factory()->create([
        'ends_at' => Carbon::tomorrow(),
        'auto_renew' => true,
        'status' => SubscriptionStatus::Active,
    ]);

    $this->artisan('tashil:process-subscriptions')
        ->assertSuccessful();

    $invoiceTable = (new Invoice)->getTable();

    $this->assertDatabaseMissing($invoiceTable, [
        'subscription_id' => $subscription->id,
    ]);
    
    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active);
});
