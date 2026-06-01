<?php

use Foysal50x\Tashil\Contracts\FeatureUsageRepositoryInterface;
use Foysal50x\Tashil\Contracts\MeteredBilling;
use Foysal50x\Tashil\Contracts\SubscriptionFeatureRepositoryInterface;
use Foysal50x\Tashil\Contracts\UsageLogRepositoryInterface;
use Foysal50x\Tashil\Enums\FeatureType;
use Foysal50x\Tashil\Events\MeteredCharged;
use Foysal50x\Tashil\Events\MeteredChargeRejected;
use Foysal50x\Tashil\Exceptions\MeteredBillingNotConfiguredException;
use Foysal50x\Tashil\Managers\DatabaseManager;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\FeatureUsage;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\SubscriptionEvent;
use Foysal50x\Tashil\Models\UsageLog;
use Foysal50x\Tashil\Services\EventStore;
use Foysal50x\Tashil\Services\Providers\NullMeteredBilling;
use Foysal50x\Tashil\Services\UsageService;
use Foysal50x\Tashil\Tests\Fixtures\FakeMeteredBilling;
use Foysal50x\Tashil\Tests\Fixtures\SelfBillingUser;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $this->feature = Feature::create([
        'name' => 'AI Tokens',
        'slug' => 'ai-tokens',
        'type' => FeatureType::Metered->value,
    ]);

    $this->package = Package::create([
        'name'             => 'Pay-as-you-go',
        'slug'             => 'payg',
        'price'            => 0,
        'currency'         => 'USD',
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    // Pivot value is the unit price (USD per unit) — stored as a string,
    // parsed by UsageService when computing the charge amount.
    $this->package->features()->attach($this->feature, ['value' => '0.001']);

    $this->user = createUser();
    $this->subscription = app('tashil')->subscription()->subscribe($this->user, $this->package);
});

it('builder marks features as Metered', function () {
    $f = app('tashil')->feature('compute-hours')->metered()->create();

    expect($f->type)->toBe(FeatureType::Metered);
    expect($f->isMetered())->toBeTrue();
});

it('snapshots the metered feature with the unit_price as value and no limit on the counter', function () {
    $snapshot = $this->subscription->currentFeatures()->first();
    expect($snapshot->feature_type)->toBe(FeatureType::Metered);
    expect($snapshot->value)->toBe('0.001');

    $counter = FeatureUsage::where('subscription_id', $this->subscription->id)->first();
    expect($counter->limit_value)->toBeNull();
});

it('charges the provider then advances the counter and writes a log on success', function () {
    Event::fake([MeteredCharged::class, MeteredChargeRejected::class]);

    $provider = new FakeMeteredBilling(balance: 10.0);
    app()->instance(MeteredBilling::class, $provider);

    $ok = $this->user->useFeature('ai-tokens', 100); // 100 × 0.001 = 0.1 USD

    expect($ok)->toBeTrue();
    expect(round($provider->balance, 4))->toBe(9.9);
    expect($provider->chargeCallCount())->toBe(1);

    $counter = FeatureUsage::where('subscription_id', $this->subscription->id)->first();
    expect((float) $counter->usage)->toBe(100.0);

    $log = UsageLog::first();
    expect((float) $log->amount)->toBe(100.0);
    expect((float) $log->new_usage)->toBe(100.0);
    expect($log->metadata['unit_price'])->toBe(0.001);
    expect(round((float) $log->metadata['amount'], 4))->toBe(0.1);
    expect($log->metadata['currency'])->toBe('USD');

    expect(SubscriptionEvent::where('event_type', 'usage.metered_charged')->count())->toBe(1);

    Event::assertDispatched(MeteredCharged::class);
    Event::assertNotDispatched(MeteredChargeRejected::class);
});

it('rejects consume when the provider declines and writes nothing', function () {
    Event::fake([MeteredCharged::class, MeteredChargeRejected::class]);

    $provider = new FakeMeteredBilling(balance: 0.0);
    app()->instance(MeteredBilling::class, $provider);

    $ok = $this->user->useFeature('ai-tokens', 50);

    expect($ok)->toBeFalse();
    expect($provider->balance)->toBe(0.0);
    expect(UsageLog::count())->toBe(0);
    expect(SubscriptionEvent::where('event_type', 'usage.metered_charged')->count())->toBe(0);
    expect((float) FeatureUsage::first()->usage)->toBe(0.0);

    Event::assertDispatched(MeteredChargeRejected::class);
    Event::assertNotDispatched(MeteredCharged::class);
});

it('passes an idempotency_key + context to the provider', function () {
    $provider = new FakeMeteredBilling(balance: 100.0);
    app()->instance(MeteredBilling::class, $provider);

    $this->user->useFeature('ai-tokens', 1);

    $ctx = collect($provider->calls)->firstWhere('method', 'charge')['context'];
    expect($ctx)->toHaveKey('idempotency_key');
    expect($ctx['idempotency_key'])->toStartWith('metered:' . $this->subscription->id . ':' . $this->feature->id . ':');
    expect($ctx['feature_slug'])->toBe('ai-tokens');
    expect($ctx['units'])->toBe(1.0);
    expect($ctx['unit_price'])->toBe(0.001);
});

it('uses the package currency when charging', function () {
    $provider = new FakeMeteredBilling(balance: 100.0);
    app()->instance(MeteredBilling::class, $provider);

    $this->package->update(['currency' => 'EUR']);

    $this->user->useFeature('ai-tokens', 1);

    $call = collect($provider->calls)->firstWhere('method', 'charge');
    expect($call['currency'])->toBe('EUR');
});

it('check() delegates to hasSufficientBalance for metered features', function () {
    $provider = new FakeMeteredBilling(balance: 0.0005);
    app()->instance(MeteredBilling::class, $provider);

    expect(app('tashil')->usage()->check($this->subscription, 'ai-tokens', 1))->toBeFalse();
    expect(app('tashil')->usage()->check($this->subscription, 'ai-tokens', 0.1))->toBeTrue();
});

it('reportStorage refuses metered features', function () {
    expect(app('tashil')->usage()->reportStorage($this->subscription, 'ai-tokens', 99))->toBeFalse();
});

it('throws MeteredBillingNotConfiguredException when no real provider is bound', function () {
    app()->instance(MeteredBilling::class, new NullMeteredBilling);

    expect(fn () => $this->user->useFeature('ai-tokens', 1))
        ->toThrow(MeteredBillingNotConfiguredException::class);
});

it('prefers a self-implementing subscriber over the container binding', function () {
    Event::fake([MeteredCharged::class, MeteredChargeRejected::class]);
    SelfBillingUser::reset();

    // Wire the container binding to throw — if UsageService falls back to
    // it, we'll know because the test will throw instead of charging.
    app()->instance(MeteredBilling::class, new NullMeteredBilling);

    $selfUser = SelfBillingUser::create([
        'name'  => 'Self',
        'email' => 'self-' . uniqid() . '@example.com',
    ]);
    $selfUser->setBalance(5.0);

    app('tashil')->subscription()->subscribe($selfUser, $this->package);

    $ok = $selfUser->useFeature('ai-tokens', 1000); // 1000 × 0.001 = 1.00 USD

    expect($ok)->toBeTrue();
    expect($selfUser->balance())->toBe(4.0);

    $calls = $selfUser->chargeCalls();
    expect($calls)->toHaveCount(1);
    expect($calls[0]['currency'])->toBe('USD');
    expect($calls[0]['amount'])->toBe(1.0);
    expect($calls[0]['context']['feature_slug'])->toBe('ai-tokens');

    Event::assertDispatched(MeteredCharged::class);
});

it('passes a caller-supplied idempotency_key through to the provider unchanged', function () {
    $provider = new FakeMeteredBilling(balance: 100.0);
    app()->instance(MeteredBilling::class, $provider);

    $token = 'req-7f3a-deadbeef';
    $this->user->useFeature('ai-tokens', 1, idempotencyKey: $token);

    $ctx = collect($provider->calls)->firstWhere('method', 'charge')['context'];
    expect($ctx['idempotency_key'])->toBe($token);
});

it('generates a UUID idempotency key when the caller does not supply one', function () {
    $provider = new FakeMeteredBilling(balance: 100.0);
    app()->instance(MeteredBilling::class, $provider);

    $this->user->useFeature('ai-tokens', 1);
    $this->user->useFeature('ai-tokens', 1);

    $charges = collect($provider->calls)->where('method', 'charge')->values();
    expect($charges)->toHaveCount(2);
    expect($charges[0]['context']['idempotency_key'])->not->toBe($charges[1]['context']['idempotency_key']);
    expect($charges[0]['context']['idempotency_key'])->toStartWith('metered:');
});

it('rejects metered consume on negative or zero amount before calling the provider', function () {
    $provider = new FakeMeteredBilling(balance: 100.0);
    app()->instance(MeteredBilling::class, $provider);

    expect($this->user->useFeature('ai-tokens', -1))->toBeFalse();
    expect($this->user->useFeature('ai-tokens', 0))->toBeFalse();
    expect($provider->chargeCallCount())->toBe(0);
});

it('logs critically and re-throws when the provider charges but the DB write fails', function () {
    $provider = new FakeMeteredBilling(balance: 100.0);
    app()->instance(MeteredBilling::class, $provider);

    // Build a UsageService directly with a broken log repo so the inner
    // transaction throws after the provider has already charged — the
    // orphan-charge scenario the Log::critical path is meant to catch.
    $brokenLog = Mockery::mock(UsageLogRepositoryInterface::class);
    $brokenLog->shouldReceive('create')->andThrow(new RuntimeException('simulated DB outage'));

    $usageService = new UsageService(
        app(DatabaseManager::class),
        app(FeatureUsageRepositoryInterface::class),
        app(SubscriptionFeatureRepositoryInterface::class),
        $brokenLog,
        app(EventStore::class),
    );

    Log::spy();

    expect(fn () => $usageService->increment($this->subscription, 'ai-tokens', 50, 'orphan-test-1'))
        ->toThrow(RuntimeException::class, 'simulated DB outage');

    Log::shouldHaveReceived('critical')
        ->withArgs(function ($message, $context) {
            return str_contains($message, 'Metered charge succeeded but Tashil could not record it')
                && $context['idempotency_key'] === 'orphan-test-1'
                && $context['feature_slug'] === 'ai-tokens'
                && $context['units'] === 50.0;
        })
        ->once();

    // Provider was charged before the DB write blew up.
    expect($provider->chargeCallCount())->toBe(1);
});

it('rejects consume when the self-implementing subscriber declines the charge', function () {
    Event::fake([MeteredCharged::class, MeteredChargeRejected::class]);
    SelfBillingUser::reset();
    app()->instance(MeteredBilling::class, new NullMeteredBilling);

    $selfUser = SelfBillingUser::create([
        'name'  => 'Self',
        'email' => 'self-' . uniqid() . '@example.com',
    ]);
    $selfUser->setBalance(0.0);

    app('tashil')->subscription()->subscribe($selfUser, $this->package);

    $ok = $selfUser->useFeature('ai-tokens', 1);

    expect($ok)->toBeFalse();
    expect($selfUser->balance())->toBe(0.0);

    Event::assertDispatched(MeteredChargeRejected::class);
    Event::assertNotDispatched(MeteredCharged::class);
});
