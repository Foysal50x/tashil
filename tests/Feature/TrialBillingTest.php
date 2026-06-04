<?php

use Foysal50x\Tashil\Enums\InvoiceKind;
use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\FeatureUsage;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');
    Carbon::setTestNow('2026-03-01 00:00:00');

    $this->apiFeature = Feature::create([
        'name'         => 'API',
        'slug'         => 'api',
        'type'         => 'limit',
        'reset_period' => 'monthly',
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

it('does not bill a subscription still on trial even after its first billing window elapses (C3)', function () {
    // Monthly plan with a 45-day trial — the trial outlives the first
    // one-month billing window, the exact case that used to bill mid-trial.
    $plan = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 30.00,
        'billing_period'   => Period::Month,
        'billing_interval' => 1,
        'trial_days'       => 45,
    ]);

    $user = createUser();
    $sub = Tashil::subscription()->subscribe($user, $plan, withTrial: true);

    expect($sub->status)->toBe(SubscriptionStatus::OnTrial);
    // Trials are never billed up front.
    expect(Invoice::where('subscription_id', $sub->id)->count())->toBe(0);

    // Move 31 days forward — past the first billing window (Apr 1) but still
    // inside the 45-day trial (ends Apr 15).
    $this->travel(31)->days();

    $this->artisan('tashil:renew-subscriptions')->assertSuccessful();

    // No invoice was issued; the subscription is still on trial.
    expect(Invoice::where('subscription_id', $sub->id)->count())->toBe(0);
    expect($sub->refresh()->status)->toBe(SubscriptionStatus::OnTrial);
});

it('convertTrial anchors the first paid period to the conversion moment and issues an initial invoice', function () {
    $plan = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 30.00,
        'billing_period'   => Period::Month,
        'billing_interval' => 1,
        'trial_days'       => 14,
    ]);
    $plan->features()->attach($this->apiFeature, ['value' => '1000']);

    $user = createUser();
    $sub = Tashil::subscription()->subscribe($user, $plan, withTrial: true);

    // Convert 10 days in.
    $this->travel(10)->days();
    $convertedAt = now();
    $sub = Tashil::subscription()->convertTrial($sub);

    expect($sub->status)->toBe(SubscriptionStatus::Active);
    expect($sub->trial_converted_at->toDateTimeString())->toBe($convertedAt->toDateTimeString());

    // The paid period starts at conversion, NOT at the original subscribe —
    // this is the fix for the free-first-period-after-trial.
    expect($sub->current_period_start->toDateTimeString())->toBe($convertedAt->toDateTimeString());
    expect($sub->current_period_end->toDateTimeString())->toBe($convertedAt->copy()->addMonth()->toDateTimeString());
    expect($sub->ends_at->toDateTimeString())->toBe($sub->current_period_end->toDateTimeString());

    // Counters re-anchored to conversion.
    $usage = FeatureUsage::where('subscription_id', $sub->id)->first();
    expect($usage->period_start->toDateTimeString())->toBe($convertedAt->toDateTimeString());

    // First paid-period invoice issued, kind=initial (paying it records the
    // payment without advancing the just-anchored period).
    $invoice = Invoice::where('subscription_id', $sub->id)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->kind)->toBe(InvoiceKind::Initial);
    expect((float) $invoice->amount)->toBe(30.00);
});

it('bills a converted trial for renewal one period after conversion, not after subscribe', function () {
    $plan = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 30.00,
        'billing_period'   => Period::Month,
        'billing_interval' => 1,
        'trial_days'       => 14,
    ]);

    $user = createUser();
    $sub = Tashil::subscription()->subscribe($user, $plan, withTrial: true);

    // Convert on day 10 → paid period runs Mar 11 .. Apr 11.
    $this->travel(10)->days();
    $sub = Tashil::subscription()->convertTrial($sub);
    // Pay the initial invoice so the period is settled (initial does not advance).
    Invoice::where('subscription_id', $sub->id)->where('kind', InvoiceKind::Initial)->first()->markAsPaid();
    $sub->refresh();
    $paidPeriodEnd = $sub->current_period_end->copy();

    // Day 35 (Apr 5) — before the converted period ends (Apr 11). Renewal must
    // not fire yet.
    $this->travelTo(Carbon::parse('2026-04-05'));
    $this->artisan('tashil:renew-subscriptions')->assertSuccessful();
    expect(Invoice::where('subscription_id', $sub->id)->where('kind', InvoiceKind::Renewal)->count())->toBe(0);

    // Just past the converted period end → a renewal invoice is issued.
    $this->travelTo($paidPeriodEnd->copy()->addDay());
    $this->artisan('tashil:renew-subscriptions')->assertSuccessful();
    expect(Invoice::where('subscription_id', $sub->id)->where('kind', InvoiceKind::Renewal)->count())->toBe(1);
    $renewal = Invoice::where('subscription_id', $sub->id)->where('kind', InvoiceKind::Renewal)->first();
    expect($renewal->status)->toBe(InvoiceStatus::Pending);
});

it('never bills a trial that expires without converting', function () {
    $plan = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 30.00,
        'billing_period'   => Period::Month,
        'billing_interval' => 1,
        'trial_days'       => 14,
    ]);

    $user = createUser();
    $sub = Tashil::subscription()->subscribe($user, $plan, withTrial: true);

    $this->travel(15)->days();
    $this->artisan('tashil:expire-trials')->assertSuccessful();
    $this->artisan('tashil:renew-subscriptions')->assertSuccessful();

    expect($sub->refresh()->status)->toBe(SubscriptionStatus::Expired);
    expect(Invoice::where('subscription_id', $sub->id)->count())->toBe(0);
});
