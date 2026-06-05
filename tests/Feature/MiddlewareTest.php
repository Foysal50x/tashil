<?php

declare(strict_types=1);

use Foysal50x\Tashil\Contracts\MeteredBilling;
use Foysal50x\Tashil\Http\Middleware\EnsureFeature;
use Foysal50x\Tashil\Http\Middleware\EnsurePlan;
use Foysal50x\Tashil\Http\Middleware\EnsureSubscribed;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Tashil;
use Foysal50x\Tashil\Tests\Fixtures\FakeMeteredBilling;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $this->boolFeature = Feature::create([
        'name' => 'Dark Mode',
        'slug' => 'dark-mode',
        'type' => 'boolean',
    ]);

    $this->pro = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 9.99,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);
    $this->pro->features()->attach($this->boolFeature, ['value' => 'true']);

    $this->basic = Package::create([
        'name'             => 'Basic',
        'slug'             => 'basic',
        'price'            => 0,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    $this->user = createUser();

    Route::middleware(EnsureSubscribed::class)->get('/test/subscribed', fn () => 'ok');
    Route::middleware(EnsurePlan::class . ':pro')->get('/test/plan-pro', fn () => 'ok');
    Route::middleware(EnsureFeature::class . ':dark-mode')->get('/test/feature-dark', fn () => 'ok');
});

afterEach(function () {
    app(Tashil::class)->forgetSubscribableResolver();
});

it('subscribed middleware aborts 403 when no subscribable resolves', function () {
    $this->get('/test/subscribed')->assertStatus(403);
});

it('subscribed middleware aborts 403 when no valid subscription', function () {
    app(Tashil::class)->resolveSubscribableUsing(fn () => $this->user);
    $this->get('/test/subscribed')->assertStatus(403);
});

it('subscribed middleware passes when subscriber holds a valid subscription', function () {
    app(Tashil::class)->resolveSubscribableUsing(fn () => $this->user);
    subscribeActive($this->user, $this->pro);

    $this->get('/test/subscribed')->assertOk()->assertSee('ok');
});

it('plan middleware passes on matching slug and aborts on mismatch', function () {
    app(Tashil::class)->resolveSubscribableUsing(fn () => $this->user);
    app('tashil')->subscription()->subscribe($this->user, $this->basic);

    $this->get('/test/plan-pro')->assertStatus(403);

    $this->user->switchPlan($this->pro);

    $this->get('/test/plan-pro')->assertOk();
});

it('feature middleware honors per-feature gating', function () {
    app(Tashil::class)->resolveSubscribableUsing(fn () => $this->user);
    app('tashil')->subscription()->subscribe($this->user, $this->basic);

    $this->get('/test/feature-dark')->assertStatus(403);

    $this->user->switchPlan($this->pro);

    $this->get('/test/feature-dark')->assertOk();
});

it('feature middleware gates metered features by provider balance', function () {
    $aiTokens = Feature::create([
        'name' => 'AI Tokens',
        'slug' => 'ai-tokens',
        'type' => 'metered',
    ]);
    $payg = Package::create([
        'name'             => 'PAYG',
        'slug'             => 'payg',
        'price'            => 0,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);
    $payg->features()->attach($aiTokens, ['value' => '0.001']);

    app(Tashil::class)->resolveSubscribableUsing(fn () => $this->user);
    app('tashil')->subscription()->subscribe($this->user, $payg);

    Route::middleware(EnsureFeature::class . ':ai-tokens')->get('/test/feature-ai', fn () => 'ok');

    $broke = new FakeMeteredBilling(balance: 0);
    app()->instance(MeteredBilling::class, $broke);
    $this->get('/test/feature-ai')->assertStatus(403);

    $rich = new FakeMeteredBilling(balance: 10);
    app()->instance(MeteredBilling::class, $rich);
    $this->get('/test/feature-ai')->assertOk();
});
