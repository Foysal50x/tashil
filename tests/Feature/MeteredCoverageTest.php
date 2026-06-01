<?php

use Foysal50x\Tashil\Contracts\MeteredBilling;
use Foysal50x\Tashil\Contracts\Subscribable;
use Foysal50x\Tashil\Exceptions\MeteredBillingNotConfiguredException;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Services\Providers\NullMeteredBilling;
use Foysal50x\Tashil\Tests\Fixtures\FakeMeteredBilling;
use Illuminate\Database\Eloquent\Relations\MorphMany;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');
});

it('NullMeteredBilling returns safe defaults on read paths and throws only on charge', function () {
    $null = new NullMeteredBilling;
    $subscriber = new class implements Subscribable
    {
        public function subscriptions(): MorphMany
        {
            throw new LogicException('not used');
        }

        public function resolveSubscription(): ?Subscription
        {
            return null;
        }

        public function getSubscriberKey(): int|string
        {
            return 1;
        }

        public function getSubscriberType(): string
        {
            return 'test';
        }
    };

    // Read paths degrade — middleware + Blade gating stays at "deny",
    // never 500. Empty balance, insufficient for anything.
    expect($null->getBalance($subscriber, 'USD'))->toBe(0.0);
    expect($null->hasSufficientBalance($subscriber, 'USD', 1.0))->toBeFalse();
    expect($null->hasSufficientBalance($subscriber, 'USD', 0.0001))->toBeFalse();

    // charge still throws — silently dropping a money-moving call is
    // a much worse failure mode than crashing the request.
    expect(fn () => $null->charge($subscriber, 'USD', 1.0, ['feature_slug' => 'x']))
        ->toThrow(MeteredBillingNotConfiguredException::class);
});

it('MeteredBillingNotConfiguredException names the offending feature', function () {
    $e = MeteredBillingNotConfiguredException::forFeature('my-feature');

    expect($e->getMessage())->toContain("'my-feature'");
    expect($e->getMessage())->toContain('MeteredBilling');
});

it('parseUnitPrice rejects non-numeric snapshot values', function () {
    $feature = Feature::create([
        'name' => 'AI Tokens',
        'slug' => 'ai-tokens',
        'type' => 'metered',
    ]);

    $package = Package::create([
        'name'             => 'PAYG',
        'slug'             => 'payg',
        'price'            => 0,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    // Misconfigured: pivot value is a string, not a number.
    $package->features()->attach($feature, ['value' => 'invalid']);

    $user = createUser();
    app('tashil')->subscription()->subscribe($user, $package);

    app()->instance(MeteredBilling::class, new FakeMeteredBilling(balance: 1000));

    expect($user->useFeature('ai-tokens', 1))->toBeFalse();
});

it('falls back to tashil.currency config when the package is no longer resolvable', function () {
    config()->set('tashil.currency', 'EUR');

    $feature = Feature::create([
        'name' => 'AI Tokens',
        'slug' => 'ai-tokens',
        'type' => 'metered',
    ]);

    $package = Package::create([
        'name'             => 'PAYG',
        'slug'             => 'payg',
        'price'            => 0,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);
    $package->features()->attach($feature, ['value' => '0.5']);

    $user = createUser();
    $subscription = app('tashil')->subscription()->subscribe($user, $package);

    // Soft-delete the package so $subscription->package resolves null.
    // This is the realistic path that hits the config fallback in
    // UsageService::subscriptionCurrency.
    $package->delete();
    $subscription->load('subscriber');
    $subscription->unsetRelation('package');

    $provider = new FakeMeteredBilling(balance: 10);
    app()->instance(MeteredBilling::class, $provider);

    app('tashil')->usage()->increment($subscription, 'ai-tokens', 1);

    $charge = collect($provider->calls)->firstWhere('method', 'charge');
    expect($charge['currency'])->toBe('EUR');
});
