<?php

declare(strict_types=1);

use Foysal50x\Tashil\Enums\InvoiceKind;
use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Events\SubscriptionPlanChanged;
use Foysal50x\Tashil\Exceptions\SubscriptionException;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\FeatureUsage;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\SubscriptionFeature;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');
    Carbon::setTestNow('2026-03-01 00:00:00');

    $this->api = Feature::create([
        'name'         => 'API',
        'slug'         => 'api',
        'type'         => 'limit',
        'reset_period' => 'monthly',
    ]);

    $this->basic = Package::create([
        'name'             => 'Basic',
        'slug'             => 'basic',
        'price'            => 10.00,
        'billing_period'   => Period::Month,
        'billing_interval' => 1,
    ]);
    $this->basic->features()->attach($this->api, ['value' => '100']);

    $this->pro = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 30.00,
        'billing_period'   => Period::Month,
        'billing_interval' => 1,
    ]);
    $this->pro->features()->attach($this->api, ['value' => '1000']);

    $this->user = createUser();
});

afterEach(function () {
    Carbon::setTestNow();
});

it('prorates an upgrade, bills the delta immediately, keeps the period, and carries usage forward', function () {
    Event::fake([SubscriptionPlanChanged::class]);

    $sub = subscribeActive($this->user, $this->basic); // period 2026-03-01 .. 2026-04-01
    $periodEnd = $sub->current_period_end->copy();
    Tashil::usage()->increment($sub, 'api', 40);

    // Halfway-ish: 16 of 31 days remain.
    $this->travelTo(Carbon::parse('2026-03-16'));
    $sub = Tashil::subscription()->changePlan($sub, $this->pro);

    // Same row, new package, unchanged period.
    expect($sub->package_id)->toBe($this->pro->id);
    expect($sub->current_period_end->toDateTimeString())->toBe($periodEnd->toDateTimeString());

    // Usage carried forward (not reset); cap updated to the new plan.
    $usage = FeatureUsage::where('subscription_id', $sub->id)->first();
    expect((float) $usage->usage)->toBe(40.0);
    expect((float) $usage->limit_value)->toBe(1000.0);

    // Snapshot superseded + replaced (history preserved).
    expect($sub->currentFeatures()->count())->toBe(1);
    expect($sub->currentFeatures()->first()->value)->toBe('1000');
    expect(SubscriptionFeature::where('subscription_id', $sub->id)->whereNotNull('superseded_at')->count())->toBe(1);

    // Proration invoice for (30 - 10) × 16/31 = 10.32.
    $invoice = Invoice::where('subscription_id', $sub->id)->where('kind', InvoiceKind::Proration)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->status)->toBe(InvoiceStatus::Pending);
    expect(round((float) $invoice->amount, 2))->toBe(10.32);

    expect(subscriptionEventCount($sub->id, 'subscription.plan_changed'))->toBe(1);
    Event::assertDispatched(SubscriptionPlanChanged::class, fn ($e) => $e->newPackage->id === $this->pro->id && round($e->prorationAmount, 2) === 10.32);
});

it('defers a downgrade to period end via scheduleDowngrade with no immediate charge', function () {
    $sub = subscribeActive($this->user, $this->pro);
    $periodEnd = $sub->current_period_end->copy();

    $this->travelTo(Carbon::parse('2026-03-16'));
    $result = Tashil::subscription()->changePlan($sub, $this->basic);

    // Still on the current (higher) plan; the change is queued.
    expect($result->package_id)->toBe($this->pro->id);
    expect($result->pending_package_id)->toBe($this->basic->id);
    expect($result->pending_change_at->toDateTimeString())->toBe($periodEnd->toDateTimeString());

    // No proration invoice for a downgrade.
    expect(Invoice::where('subscription_id', $sub->id)->where('kind', InvoiceKind::Proration)->count())->toBe(0);
    expect(subscriptionEventCount($sub->id, 'subscription.pending_change_scheduled'))->toBe(1);
});

it('changes the plan without an invoice when the prorated delta is below the minimum', function () {
    config()->set('tashil.billing.min_proration_amount', 100.0);
    Event::fake([SubscriptionPlanChanged::class]);

    $sub = subscribeActive($this->user, $this->basic);
    $this->travelTo(Carbon::parse('2026-03-16'));

    $sub = Tashil::subscription()->changePlan($sub, $this->pro);

    // Plan still changes in place...
    expect($sub->package_id)->toBe($this->pro->id);
    // ...but the dust-sized delta is not invoiced.
    expect(Invoice::where('subscription_id', $sub->id)->where('kind', InvoiceKind::Proration)->count())->toBe(0);
    Event::assertDispatched(SubscriptionPlanChanged::class);
});

it('refuses to prorate a plan change across currencies', function () {
    $eurPro = Package::create([
        'name'             => 'Pro EUR',
        'slug'             => 'pro-eur',
        'price'            => 30.00,
        'currency'         => 'EUR',
        'billing_period'   => Period::Month,
        'billing_interval' => 1,
    ]);

    $sub = subscribeActive($this->user, $this->basic);
    $this->travelTo(Carbon::parse('2026-03-16'));

    expect(fn () => Tashil::subscription()->changePlan($sub, $eurPro))
        ->toThrow(SubscriptionException::class, 'across currencies');
});

it('classifies an upgrade across billing cadences using normalized monthly price', function () {
    // Yearly plan at $120/yr normalizes to $10/mo — cheaper than Pro's $30/mo,
    // so moving yearly → monthly-pro is an upgrade and applies in place.
    $yearly = Package::create([
        'name'             => 'Yearly',
        'slug'             => 'yearly',
        'price'            => 120.00,
        'billing_period'   => Period::Year,
        'billing_interval' => 1,
    ]);
    $yearly->features()->attach($this->api, ['value' => '100']);

    $sub = subscribeActive($this->user, $yearly);
    $this->travelTo(Carbon::parse('2026-06-01'));

    $sub = Tashil::subscription()->changePlan($sub, $this->pro);

    expect($sub->package_id)->toBe($this->pro->id);
    expect($sub->pending_package_id)->toBeNull(); // applied now, not scheduled
});
