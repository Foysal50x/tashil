<?php

declare(strict_types=1);

use Foysal50x\Tashil\Enums\InvoiceKind;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Events\SubscriptionCancelled;
use Foysal50x\Tashil\Events\SubscriptionCreated;
use Foysal50x\Tashil\Events\SubscriptionExpired;
use Foysal50x\Tashil\Events\SubscriptionResumed;
use Foysal50x\Tashil\Events\SubscriptionSwitched;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\FeatureUsage;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionEvent;
use Foysal50x\Tashil\Models\SubscriptionFeature;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $this->feature = Feature::create([
        'name' => 'API Requests',
        'slug' => 'api-requests',
        'type' => 'limit',
    ]);

    $this->toggle = Feature::create([
        'name' => 'Email Support',
        'slug' => 'email-support',
        'type' => 'boolean',
    ]);

    $this->package = Package::create([
        'name'             => 'Pro Plan',
        'slug'             => 'pro-plan',
        'price'            => 29.99,
        'billing_period'   => 'month',
        'billing_interval' => 1,
        'trial_days'       => 14,
    ]);

    $this->package->features()->attach($this->feature, ['value' => '10000']);
    $this->package->features()->attach($this->toggle, ['value' => 'true']);

    $this->user = createUser();
});

it('subscribes a priced plan as pending, issues an initial invoice, and appends a created event', function () {
    // Scope the fake to the asserted event so the InvoiceObserver (a model
    // event listener) still runs and stamps the invoice number.
    Event::fake([SubscriptionCreated::class]);

    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);

    expect($subscription)->toBeInstanceOf(Subscription::class);
    // Priced plan under activate-on-payment → pending, no access, no period
    // until the initial invoice is paid.
    expect($subscription->status)->toBe(SubscriptionStatus::Pending);
    expect($subscription->isValid())->toBeFalse();
    expect($subscription->package_id)->toBe($this->package->id);
    expect($subscription->subscriber_id)->toBe($this->user->id);
    expect($subscription->auto_renew)->toBeTrue();
    expect($subscription->current_period_start)->toBeNull();
    expect($subscription->current_period_end)->toBeNull();

    $invoice = Invoice::where('subscription_id', $subscription->id)->first();
    expect($invoice)->not->toBeNull();
    expect($invoice->kind)->toBe(InvoiceKind::Initial);
    expect((float) $invoice->amount)->toBe((float) $this->package->price);

    Event::assertDispatched(SubscriptionCreated::class);

    $events = SubscriptionEvent::where('subscription_id', $subscription->id)->orderBy('sequence_num')->get();
    expect($events)->toHaveCount(1);
    expect($events[0]->event_type)->toBe('subscription.created');
    expect($events[0]->sequence_num)->toBe(1);
});

it('subscribes with trial', function () {
    $this->travelTo(now()->startOfDay());

    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package, withTrial: true);

    expect($subscription->status)->toBe(SubscriptionStatus::OnTrial);
    expect($subscription->trial_started_at)->not->toBeNull();
    expect($subscription->trial_ends_at)->not->toBeNull();
    expect((int) abs(round($subscription->trial_ends_at->diffInDays(now()))))->toBe(14);
});

it('writes immutable feature snapshot + counter on subscribe', function () {
    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);

    expect($subscription->subscriptionFeatures)->toHaveCount(2);
    expect($subscription->featureUsages)->toHaveCount(2);

    $apiSnapshot = SubscriptionFeature::where('subscription_id', $subscription->id)
        ->where('feature_id', $this->feature->id)
        ->first();
    expect($apiSnapshot->value)->toBe('10000');
    expect($apiSnapshot->superseded_at)->toBeNull();

    $apiUsage = FeatureUsage::where('subscription_id', $subscription->id)
        ->where('feature_id', $this->feature->id)
        ->first();
    expect((float) $apiUsage->limit_value)->toBe(10000.0);
    expect((float) $apiUsage->usage)->toBe(0.0);
});

it('cancels a subscription immediately', function () {
    Event::fake([SubscriptionCancelled::class]);

    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);

    $cancelled = app('tashil')->subscription()->cancel($subscription, immediate: true, reason: 'Too expensive');

    expect($cancelled->status)->toBe(SubscriptionStatus::Cancelled);
    expect($cancelled->cancelled_at)->not->toBeNull();
    expect($cancelled->cancellation_reason)->toBe('Too expensive');
    expect($cancelled->ends_at->lessThanOrEqualTo(now()))->toBeTrue();

    Event::assertDispatched(SubscriptionCancelled::class, fn ($e) => $e->immediate === true);
});

it('grace-cancels and keeps the user subscribed until ends_at', function () {
    $subscription = subscribeActive($this->user, $this->package);
    $originalEndsAt = $subscription->ends_at->copy();

    $cancelled = app('tashil')->subscription()->cancel($subscription);

    expect($cancelled->status)->toBe(SubscriptionStatus::PendingCancellation);
    expect($cancelled->auto_renew)->toBeFalse();
    expect($cancelled->cancellation_effective_at)->not->toBeNull();
    expect($cancelled->ends_at->format('Y-m-d'))->toBe($originalEndsAt->format('Y-m-d'));
    expect($cancelled->isValid())->toBeTrue();

    $this->user->clearSubscriptionCache();
    expect($this->user->subscribed())->toBeTrue();
});

it('resumes a pending-cancellation subscription', function () {
    Event::fake([SubscriptionResumed::class]);

    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);
    app('tashil')->subscription()->cancel($subscription);

    $resumed = app('tashil')->subscription()->resume($subscription->refresh());

    expect($resumed->status)->toBe(SubscriptionStatus::Active);
    expect($resumed->cancelled_at)->toBeNull();
    expect($resumed->cancellation_effective_at)->toBeNull();
    expect($resumed->auto_renew)->toBeTrue();

    Event::assertDispatched(SubscriptionResumed::class);
});

it('expires a subscription via the service and emits an event', function () {
    Event::fake([SubscriptionExpired::class]);

    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);

    $expired = app('tashil')->subscription()->expire($subscription);

    expect($expired->status)->toBe(SubscriptionStatus::Expired);
    Event::assertDispatched(SubscriptionExpired::class);
});

it('switches a plan and emits a switched event', function () {
    Event::fake([SubscriptionSwitched::class]);

    $newPackage = Package::create([
        'name'             => 'Enterprise',
        'slug'             => 'enterprise',
        'price'            => 99.99,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);
    $newSub = app('tashil')->subscription()->switchPlan($subscription, $newPackage);

    expect($newSub->package_id)->toBe($newPackage->id);
    expect($newSub->status)->toBe(SubscriptionStatus::Active);

    $subscription->refresh();
    expect($subscription->status)->toBe(SubscriptionStatus::Cancelled);
    expect($subscription->cancellation_reason)->toBe('Plan switch');

    Event::assertDispatched(SubscriptionSwitched::class);
});

it('handles lifetime subscription (no expiry)', function () {
    $lifetimePackage = Package::create([
        'name'             => 'Lifetime',
        'slug'             => 'lifetime',
        'price'            => 999.99,
        'billing_period'   => 'lifetime',
        'billing_interval' => 1,
    ]);

    // Lifetime is priced, so it still gates on the initial payment; once paid
    // it activates with no period/expiry.
    $subscription = subscribeActive($this->user, $lifetimePackage);

    expect($subscription->ends_at)->toBeNull();
    expect($subscription->current_period_end)->toBeNull();
    expect($subscription->isActive())->toBeTrue();
});

it('scopeActive excludes expired-by-date rows', function () {
    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);
    $subscription->update(['ends_at' => now()->subDay()]);

    expect(Subscription::active()->where('id', $subscription->id)->exists())->toBeFalse();
});

it('isOnTrial returns false for cancelled subs even with future trial date', function () {
    $subscription = app('tashil')->subscription()->subscribe($this->user, $this->package, withTrial: true);
    app('tashil')->subscription()->cancel($subscription, immediate: true);

    $subscription->refresh();
    expect($subscription->isOnTrial())->toBeFalse();
});
