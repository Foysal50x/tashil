<?php

declare(strict_types=1);

use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Events\TrialExpired;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Carbon::setTestNow('2026-01-15 00:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

it('renew-subscriptions creates an invoice when current_period_end has elapsed', function () {
    $package = Package::factory()->create(['price' => 100]);
    $subscription = Subscription::factory()->create([
        'package_id'           => $package->id,
        'status'               => SubscriptionStatus::Active,
        'auto_renew'           => true,
        'current_period_start' => now()->subMonth(),
        'current_period_end'   => now()->subDay(),
        'ends_at'              => now()->subDay(),
    ]);

    $this->artisan('tashil:renew-subscriptions')->assertSuccessful();

    $this->assertDatabaseHas((new Invoice)->getTable(), [
        'subscription_id' => $subscription->id,
        'status'          => InvoiceStatus::Pending,
    ]);
});

it('renew-subscriptions cancels when a pending invoice already exists (default policy)', function () {
    config(['tashil.renewal.on_pending_invoice' => 'cancel']);

    $package = Package::factory()->create(['price' => 100]);
    $subscription = Subscription::factory()->create([
        'package_id'           => $package->id,
        'status'               => SubscriptionStatus::Active,
        'auto_renew'           => true,
        'current_period_start' => now()->subMonth(),
        'current_period_end'   => now()->subDay(),
        'ends_at'              => now()->subDay(),
    ]);

    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'status'          => InvoiceStatus::Pending,
    ]);

    $this->artisan('tashil:renew-subscriptions')->assertSuccessful();

    expect($subscription->invoices()->count())->toBe(1);
    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::PendingCancellation);
    expect($subscription->cancellation_reason)->toContain('Auto-renewal failed');
});

it('renew-subscriptions skips when policy is skip', function () {
    config(['tashil.renewal.on_pending_invoice' => 'skip']);

    $package = Package::factory()->create();
    $subscription = Subscription::factory()->create([
        'package_id'         => $package->id,
        'status'             => SubscriptionStatus::Active,
        'auto_renew'         => true,
        'current_period_end' => now()->subDay(),
    ]);
    Invoice::factory()->create([
        'subscription_id' => $subscription->id,
        'status'          => InvoiceStatus::Pending,
    ]);

    $this->artisan('tashil:renew-subscriptions')->assertSuccessful();

    expect($subscription->invoices()->count())->toBe(1);
    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active);
});

it('expire-subscriptions promotes auto_renew=false past ends_at to Expired', function () {
    $subscription = Subscription::factory()->create([
        'ends_at'    => now()->subDay(),
        'auto_renew' => false,
        'status'     => SubscriptionStatus::Active,
    ]);

    $this->artisan('tashil:expire-subscriptions')->assertSuccessful();

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired);
});

it('expire-subscriptions promotes pending-cancellation past grace to Expired', function () {
    $subscription = Subscription::factory()->create([
        'status'                    => SubscriptionStatus::PendingCancellation,
        'cancellation_effective_at' => now()->subDay(),
        'auto_renew'                => false,
    ]);

    $this->artisan('tashil:expire-subscriptions')->assertSuccessful();

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Expired);
});

it('does not expire future-period subscriptions', function () {
    $subscription = Subscription::factory()->create([
        'ends_at'    => now()->addDay(),
        'auto_renew' => false,
        'status'     => SubscriptionStatus::Active,
    ]);

    $this->artisan('tashil:expire-subscriptions')->assertSuccessful();

    expect($subscription->refresh()->status)->toBe(SubscriptionStatus::Active);
});

it('expire-trials promotes overdue trials and dispatches TrialExpired', function () {
    Event::fake([TrialExpired::class]);

    $subscription = Subscription::factory()->create([
        'status'             => SubscriptionStatus::OnTrial,
        'trial_started_at'   => now()->subDays(15),
        'trial_ends_at'      => now()->subDay(),
        'trial_converted_at' => null,
    ]);

    $this->artisan('tashil:expire-trials')->assertSuccessful();

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Expired);
    expect($subscription->trial_expired_at)->not->toBeNull();
    Event::assertDispatched(TrialExpired::class);
});
