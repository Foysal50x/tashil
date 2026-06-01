<?php

use Foysal50x\Tashil\Contracts\MeteredBilling;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Tashil;
use Foysal50x\Tashil\Tests\Fixtures\FakeMeteredBilling;
use Illuminate\Support\Facades\Blade;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $this->dark = Feature::create(['name' => 'Dark Mode', 'slug' => 'dark-mode', 'type' => 'boolean']);

    $this->pro = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 9,
        'billing_period'   => 'month',
        'billing_interval' => 1,
        'trial_days'       => 14,
    ]);
    $this->pro->features()->attach($this->dark, ['value' => 'true']);

    $this->basic = Package::create([
        'name'             => 'Basic',
        'slug'             => 'basic',
        'price'            => 0,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    $this->user = createUser();
});

afterEach(function () {
    app(Tashil::class)->forgetSubscribableResolver();
});

function renderBlade(string $template): string
{
    return Blade::render($template);
}

it('@subscribed renders the content only when a valid subscription exists', function () {
    app(Tashil::class)->resolveSubscribableUsing(fn () => $this->user);

    expect(renderBlade('@subscribed yes @else no @endsubscribed'))->toContain('no');

    app('tashil')->subscription()->subscribe($this->user, $this->basic);
    $this->user->clearSubscriptionCache();

    expect(renderBlade('@subscribed yes @else no @endsubscribed'))->toContain('yes');
});

it('@plan(slug) matches the active package slug', function () {
    app(Tashil::class)->resolveSubscribableUsing(fn () => $this->user);

    app('tashil')->subscription()->subscribe($this->user, $this->basic);
    $this->user->clearSubscriptionCache();
    expect(renderBlade("@plan('pro') yes @else no @endplan"))->toContain('no');

    $this->user->switchPlan($this->pro);
    expect(renderBlade("@plan('pro') yes @else no @endplan"))->toContain('yes');
});

it('@feature(slug) honors per-feature gating', function () {
    app(Tashil::class)->resolveSubscribableUsing(fn () => $this->user);

    app('tashil')->subscription()->subscribe($this->user, $this->basic);
    $this->user->clearSubscriptionCache();
    expect(renderBlade("@feature('dark-mode') yes @else no @endfeature"))->toContain('no');

    $this->user->switchPlan($this->pro);
    expect(renderBlade("@feature('dark-mode') yes @else no @endfeature"))->toContain('yes');
});

it('@onTrial reflects the strict isOnTrial check', function () {
    app(Tashil::class)->resolveSubscribableUsing(fn () => $this->user);

    app('tashil')->subscription()->subscribe($this->user, $this->pro, withTrial: true);
    $this->user->clearSubscriptionCache();

    expect(renderBlade('@onTrial yes @else no @endonTrial'))->toContain('yes');

    app('tashil')->subscription()->convertTrial($this->user->resolveSubscription());
    $this->user->clearSubscriptionCache();

    expect(renderBlade('@onTrial yes @else no @endonTrial'))->toContain('no');
});

it('@feature(metered-slug) checks balance via the bound MeteredBilling', function () {
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

    app()->instance(MeteredBilling::class, new FakeMeteredBilling(balance: 0));
    expect(renderBlade("@feature('ai-tokens') yes @else no @endfeature"))->toContain('no');

    app()->instance(MeteredBilling::class, new FakeMeteredBilling(balance: 5));
    expect(renderBlade("@feature('ai-tokens') yes @else no @endfeature"))->toContain('yes');
});

it('directives degrade safely when no subscribable resolves', function () {
    app(Tashil::class)->forgetSubscribableResolver();

    expect(renderBlade('@subscribed yes @else no @endsubscribed'))->toContain('no');
    expect(renderBlade("@plan('pro') yes @else no @endplan"))->toContain('no');
    expect(renderBlade("@feature('dark-mode') yes @else no @endfeature"))->toContain('no');
    expect(renderBlade('@onTrial yes @else no @endonTrial'))->toContain('no');
});
