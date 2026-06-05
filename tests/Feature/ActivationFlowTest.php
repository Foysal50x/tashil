<?php

declare(strict_types=1);

use Foysal50x\Tashil\Enums\InvoiceKind;
use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Events\SubscriptionActivated;
use Foysal50x\Tashil\Events\SubscriptionCreated;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\FeatureUsage;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $this->apiFeature = Feature::create([
        'name'         => 'API Requests',
        'slug'         => 'api-requests',
        'type'         => 'limit',
        'reset_period' => 'monthly',
    ]);

    // Priced plan: requires payment by default.
    $this->paid = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 30.00,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);
    $this->paid->features()->attach($this->apiFeature, ['value' => '10000']);

    $this->user = createUser();
});

it('subscribes a priced plan as pending with no access and issues an initial invoice', function () {
    Event::fake([SubscriptionCreated::class, SubscriptionActivated::class]);

    $sub = app('tashil')->subscription()->subscribe($this->user, $this->paid);

    // State: pending, not started, no period.
    expect($sub->status)->toBe(SubscriptionStatus::Pending);
    expect($sub->isPending())->toBeTrue();
    expect($sub->isValid())->toBeFalse();
    expect($sub->starts_at)->toBeNull();
    expect($sub->current_period_start)->toBeNull();
    expect($sub->current_period_end)->toBeNull();
    expect($sub->activated_at)->toBeNull();

    // No access anywhere while pending.
    $this->user->clearSubscriptionCache();
    expect($this->user->subscribed())->toBeFalse();
    expect($this->user->hasFeature('api-requests'))->toBeFalse();
    expect($this->user->useFeature('api-requests', 1))->toBeFalse();

    // Snapshot + counter still written (gated, not absent).
    expect($sub->subscriptionFeatures()->count())->toBe(1);
    expect($sub->featureUsages()->count())->toBe(1);

    // Exactly one initial, pending invoice for the full price.
    $invoices = Invoice::where('subscription_id', $sub->id)->get();
    expect($invoices)->toHaveCount(1);
    expect($invoices[0]->kind)->toBe(InvoiceKind::Initial);
    expect($invoices[0]->status)->toBe(InvoiceStatus::Pending);
    expect((float) $invoices[0]->amount)->toBe(30.00);

    // created fired, activated did NOT (still pending).
    Event::assertDispatched(SubscriptionCreated::class);
    Event::assertNotDispatched(SubscriptionActivated::class);

    expect(subscriptionEventCount($sub->id, 'subscription.created'))->toBe(1);
    expect(subscriptionEventCount($sub->id, 'subscription.activated'))->toBe(0);
});

it('activates a pending subscription when its initial invoice is paid, anchoring the period to payment time', function () {
    Event::fake([SubscriptionActivated::class]);
    $this->travelTo(now()->startOfDay());

    $sub = app('tashil')->subscription()->subscribe($this->user, $this->paid);
    $invoice = Invoice::where('subscription_id', $sub->id)->first();

    // Pay three days later — activation must anchor to the payment moment,
    // not the (earlier) subscribe moment.
    $this->travel(3)->days();
    $paidAt = now();
    $invoice->markAsPaid();

    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Active);
    expect($sub->isValid())->toBeTrue();
    expect($sub->activated_at->toDateTimeString())->toBe($paidAt->toDateTimeString());
    expect($sub->current_period_start->toDateTimeString())->toBe($paidAt->toDateTimeString());
    // Monthly plan → period_end is one month after payment, not after subscribe.
    expect($sub->current_period_end->toDateTimeString())->toBe($paidAt->copy()->addMonth()->toDateTimeString());
    expect($sub->ends_at->toDateTimeString())->toBe($sub->current_period_end->toDateTimeString());

    // Counters re-anchored to activation: a monthly-reset feature's window
    // starts at payment time.
    $usage = FeatureUsage::where('subscription_id', $sub->id)->first();
    expect($usage->period_start->toDateTimeString())->toBe($paidAt->toDateTimeString());
    expect($usage->period_end->toDateTimeString())->toBe($paidAt->copy()->addMonth()->toDateTimeString());

    // Access now works.
    $this->user->clearSubscriptionCache();
    expect($this->user->subscribed())->toBeTrue();
    expect($this->user->useFeature('api-requests', 5))->toBeTrue();

    Event::assertDispatched(SubscriptionActivated::class, fn ($e) => $e->subscription->id === $sub->id && $e->invoice?->id === $invoice->id);
    expect(subscriptionEventCount($sub->id, 'subscription.activated'))->toBe(1);
});

it('does not advance the period a second time when paying the initial invoice (activate, not renew)', function () {
    $this->travelTo(now()->startOfDay());

    $sub = app('tashil')->subscription()->subscribe($this->user, $this->paid);
    $invoice = Invoice::where('subscription_id', $sub->id)->first();
    $invoice->markAsPaid();

    $sub->refresh();
    // Period is exactly one month from activation — activation must not also
    // trigger advancePeriod (which would push it to two months).
    expect($sub->current_period_end->toDateTimeString())
        ->toBe($sub->current_period_start->copy()->addMonth()->toDateTimeString());
    // No renewal event from an activation.
    expect(subscriptionEventCount($sub->id, 'subscription.renewed'))->toBe(0);
});

it('activates a free (zero-price) plan immediately without an invoice', function () {
    Event::fake([SubscriptionActivated::class]);

    $free = Package::create([
        'name'             => 'Free',
        'slug'             => 'free',
        'price'            => 0.00,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    $sub = app('tashil')->subscription()->subscribe($this->user, $free);

    expect($sub->status)->toBe(SubscriptionStatus::Active);
    expect($sub->activated_at)->not->toBeNull();
    expect($sub->current_period_end)->not->toBeNull();
    expect(Invoice::where('subscription_id', $sub->id)->count())->toBe(0);

    Event::assertDispatched(SubscriptionActivated::class);
});

it('activates a priced plan immediately when requires_payment is false (offline/enterprise)', function () {
    $offline = Package::create([
        'name'             => 'Enterprise (invoiced offline)',
        'slug'             => 'ent-offline',
        'price'            => 5000.00,
        'requires_payment' => false,
        'billing_period'   => 'year',
        'billing_interval' => 1,
    ]);

    $sub = app('tashil')->subscription()->subscribe($this->user, $offline);

    expect($sub->status)->toBe(SubscriptionStatus::Active);
    expect($sub->current_period_end)->not->toBeNull();
    // Tashil issues no invoice — the host bills out of band.
    expect(Invoice::where('subscription_id', $sub->id)->count())->toBe(0);
});

it('keeps a trial subscription accessible with no invoice issued at subscribe', function () {
    $trialPlan = Package::create([
        'name'             => 'Pro Trial',
        'slug'             => 'pro-trial',
        'price'            => 30.00,
        'billing_period'   => 'month',
        'billing_interval' => 1,
        'trial_days'       => 14,
    ]);

    $sub = app('tashil')->subscription()->subscribe($this->user, $trialPlan, withTrial: true);

    expect($sub->status)->toBe(SubscriptionStatus::OnTrial);
    expect($sub->isValid())->toBeTrue();
    expect($sub->trial_ends_at)->not->toBeNull();
    // Trials never bill up front — the first invoice is issued at conversion.
    expect(Invoice::where('subscription_id', $sub->id)->count())->toBe(0);

    $this->user->clearSubscriptionCache();
    expect($this->user->subscribed())->toBeTrue();
});

it('treats activate() as a no-op for a non-pending subscription', function () {
    $sub = app('tashil')->subscription()->subscribe($this->user, $this->paid);
    Invoice::where('subscription_id', $sub->id)->first()->markAsPaid();
    $sub->refresh();

    $periodEnd = $sub->current_period_end->toDateTimeString();

    // Calling activate again must not re-anchor or double-advance.
    $again = app('tashil')->subscription()->activate($sub);
    expect($again->status)->toBe(SubscriptionStatus::Active);
    expect($again->current_period_end->toDateTimeString())->toBe($periodEnd);
    expect(subscriptionEventCount($sub->id, 'subscription.activated'))->toBe(1);
});

it('seeds requires_payment from activate_on_payment at creation, so a package made under the off default subscribes active', function () {
    // The flag is a CREATION-TIME default, not a runtime gate. A priced plan
    // created while the install-wide default is off inherits requires_payment
    // = false and therefore activates immediately (legacy access-first model).
    config()->set('tashil.billing.activate_on_payment', false);

    $legacyPaid = Package::create([
        'name'             => 'Legacy Pro',
        'slug'             => 'legacy-pro',
        'price'            => 30.00,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);
    expect($legacyPaid->requires_payment)->toBeFalse();

    $sub = app('tashil')->subscription()->subscribe($this->user, $legacyPaid);

    expect($sub->status)->toBe(SubscriptionStatus::Active);
    expect($sub->current_period_end)->not->toBeNull();
    expect(Invoice::where('subscription_id', $sub->id)->count())->toBe(0);

    $this->user->clearSubscriptionCache();
    expect($this->user->subscribed())->toBeTrue();
});

it('honors an explicit requires_payment=true even when the install-wide default is off (no silent demotion)', function () {
    // The package is authoritative at runtime: once a plan is deliberately
    // marked requires_payment=true, flipping the global default to false must
    // NOT stop it collecting payment. This is the footgun the config-first
    // check used to create — gating is decided by the stored flag, not config.
    config()->set('tashil.billing.activate_on_payment', false);

    $gatedPaid = Package::create([
        'name'             => 'Deliberately Gated',
        'slug'             => 'deliberately-gated',
        'price'            => 30.00,
        'billing_period'   => 'month',
        'billing_interval' => 1,
        'requires_payment' => true,
    ]);
    expect($gatedPaid->requires_payment)->toBeTrue();

    $sub = app('tashil')->subscription()->subscribe($this->user, $gatedPaid);

    // Still gated: pending, no access, initial invoice issued.
    expect($sub->status)->toBe(SubscriptionStatus::Pending);
    expect($sub->current_period_end)->toBeNull();
    $invoice = Invoice::where('subscription_id', $sub->id)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->kind)->toBe(InvoiceKind::Initial);

    $this->user->clearSubscriptionCache();
    expect($this->user->subscribed())->toBeFalse();

    // ...and paying it activates, proving the gate was real.
    $invoice->markAsPaid();
    $sub->refresh();
    expect($sub->status)->toBe(SubscriptionStatus::Active);
});
