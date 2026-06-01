<?php

use Foysal50x\Tashil\Contracts\Subscribable;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Tashil;
use Foysal50x\Tashil\Tests\Fixtures\User;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');

    $this->package = Package::create([
        'name'             => 'Pro',
        'slug'             => 'pro',
        'price'            => 9.99,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);
});

it('User implements Subscribable', function () {
    $user = createUser();

    expect($user)->toBeInstanceOf(Subscribable::class);
    expect($user->getSubscriberKey())->toBe($user->getKey());
    expect($user->getSubscriberType())->toBe($user->getMorphClass());
});

it('resolveSubscription returns the active subscription by default', function () {
    $user = createUser();
    $sub = app('tashil')->subscription()->subscribe($user, $this->package);

    expect($user->resolveSubscription()?->id)->toBe($sub->id);
});

it('HasSubscriptions::loadSubscription delegates to resolveSubscription', function () {
    $package2 = Package::create([
        'name'             => 'Other',
        'slug'             => 'other',
        'price'            => 19,
        'billing_period'   => 'month',
        'billing_interval' => 1,
    ]);

    $user = new class extends User
    {
        public ?Subscription $forced = null;

        public function resolveSubscription(): ?Subscription
        {
            return $this->forced;
        }
    };

    $user->name = 'A';
    $user->email = 't' . uniqid() . '@e.com';
    $user->save();

    $sub = app('tashil')->subscription()->subscribe($user, $package2);
    $user->forced = $sub;

    expect($user->loadSubscription()?->id)->toBe($sub->id);
});

it('Tashil::resolveSubscribableUsing overrides the default resolver', function () {
    $user = createUser();
    $tashil = app(Tashil::class);

    $tashil->resolveSubscribableUsing(fn () => $user);

    expect($tashil->resolveSubscribable()?->getSubscriberKey())->toBe($user->getKey());

    $tashil->forgetSubscribableResolver();
});

it('Tashil::resolveSubscribable returns null when the auth user is not Subscribable', function () {
    $tashil = app(Tashil::class);
    $tashil->forgetSubscribableResolver();

    expect($tashil->resolveSubscribable())->toBeNull();
});

it('Tashil::forgetSubscribableResolver clears the override so auth() is used again', function () {
    $user = createUser();
    $tashil = app(Tashil::class);

    $tashil->resolveSubscribableUsing(fn () => $user);
    expect($tashil->resolveSubscribable()?->getSubscriberKey())->toBe($user->getKey());

    $tashil->forgetSubscribableResolver();
    expect($tashil->resolveSubscribable())->toBeNull();
});
