<?php

use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $this->feature = Feature::create(['name' => 'API Requests', 'slug' => 'api-requests', 'type' => 'limit']);
    $this->toggle = Feature::create(['name' => 'Email Support', 'slug' => 'email-support', 'type' => 'boolean']);

    $this->package = Package::create([
        'name'             => 'Pro Plan',
        'slug'             => 'pro-plan',
        'price'            => 29.99,
        'billing_period'   => 'month',
        'billing_interval' => 1,
        'trial_days'       => 14,
    ]);
    $this->package->features()->attach($this->feature, ['value' => '1000']);
    $this->package->features()->attach($this->toggle, ['value' => 'true']);

    $this->secondPackage = Package::create([
        'name'             => 'Enterprise',
        'slug'             => 'enterprise',
        'price'            => 99.99,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    $this->user = createUser();
});

it('subscribes via trait', function () {
    $subscription = $this->user->subscribe($this->package);

    expect($subscription)->toBeInstanceOf(Subscription::class);
    expect($subscription->subscriber_id)->toBe($this->user->id);
});

it('checks subscribed status via trait', function () {
    $this->user->subscribe($this->package);
    
    // Check database
    expect($this->user->subscriptions()->count())->toBe(1);
    
    expect($this->user->subscribed())->toBeTrue();
});

it('checks subscribedTo by package model', function () {
    $this->user->subscribe($this->package);

    expect($this->user->subscribedTo($this->package))->toBeTrue();
    expect($this->user->subscribedTo($this->secondPackage))->toBeFalse();
});

it('checks subscribedTo and onPlan by slug', function () {
    $this->user->subscribe($this->package);

    expect($this->user->onPlan('pro-plan'))->toBeTrue();
    expect($this->user->onPlan('enterprise'))->toBeFalse();
});

it('gets active subscription via trait', function () {
    $this->user->subscribe($this->package);

    $sub = $this->user->subscription();
    expect($sub)->not->toBeNull();
    expect($sub->package_id)->toBe($this->package->id);
});

it('detects trial via trait', function () {
    $this->user->subscribe($this->package, withTrial: true);

    expect($this->user->onTrial())->toBeTrue();
});

it('checks feature access via trait', function () {
    $this->user->subscribe($this->package);

    expect($this->user->hasFeature('api-requests'))->toBeTrue();
    expect($this->user->hasFeature('email-support'))->toBeTrue();
    expect($this->user->hasFeature('nonexistent'))->toBeFalse();
});

it('gets feature value via trait', function () {
    $this->user->subscribe($this->package);

    expect($this->user->featureValue('api-requests'))->toBe('1000');
    expect($this->user->featureValue('email-support'))->toBe('true');
    expect($this->user->featureValue('nonexistent'))->toBeNull();
});

it('gets feature usage via trait', function () {
    $this->user->subscribe($this->package);

    expect($this->user->featureUsage('api-requests'))->toBe(0);

    $this->user->useFeature('api-requests', 50);

    expect($this->user->featureUsage('api-requests'))->toBe(50);
});

it('gets feature remaining via trait', function () {
    $this->user->subscribe($this->package);

    expect($this->user->featureRemaining('api-requests'))->toBe(1000.0);

    $this->user->useFeature('api-requests', 300);

    expect($this->user->featureRemaining('api-requests'))->toBe(700.0);
});

it('uses feature via trait', function () {
    $this->user->subscribe($this->package);

    $result = $this->user->useFeature('api-requests', 10);
    expect($result)->toBeTrue();

    // Without subscription
    $user2 = createUser(['name' => 'No Sub', 'email' => 'nosub@test.com']);
    $result = $user2->useFeature('api-requests', 10);
    expect($result)->toBeFalse();
});

it('cancels subscription via trait', function () {
    $this->user->subscribe($this->package);

    $cancelled = $this->user->cancelSubscription(reason: 'Testing');

    expect($cancelled)->not->toBeNull();
    expect($cancelled->cancelled_at)->not->toBeNull();
    expect($cancelled->cancellation_reason)->toBe('Testing');
});

it('cancels returns null when no subscription', function () {
    $result = $this->user->cancelSubscription();
    expect($result)->toBeNull();
});

it('resumes subscription via trait', function () {
    $this->user->subscribe($this->package);
    $this->user->cancelSubscription();

    $resumed = $this->user->resumeSubscription();

    expect($resumed)->not->toBeNull();
    expect($resumed->cancelled_at)->toBeNull();
});

it('switches plan via trait', function () {
    $this->user->subscribe($this->package);

    $newSub = $this->user->switchPlan($this->secondPackage);

    expect($newSub)->not->toBeNull();
    expect($newSub->package_id)->toBe($this->secondPackage->id);
    expect($this->user->onPlan('enterprise'))->toBeTrue();
});

it('gets all invoices via trait', function () {
    $this->user->subscribe($this->package);

    app('tashil')->billing()->generateInvoice($this->user->subscription());
    app('tashil')->billing()->generateInvoice($this->user->subscription());

    $invoices = $this->user->allInvoices();
    expect($invoices)->toHaveCount(2);
});

it('returns subscriptions relationship', function () {
    $this->user->subscribe($this->package);

    expect($this->user->subscriptions)->toHaveCount(1);
});
