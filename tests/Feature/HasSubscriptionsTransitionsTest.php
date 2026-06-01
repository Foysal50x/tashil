<?php

use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Facades\Tashil;
use Foysal50x\Tashil\Models\Package;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');
});

it('pauseSubscription pauses the active subscription and revokes its validity', function () {
    $package = Package::factory()->create();
    $user = createUser();
    Tashil::subscription()->subscribe($user, $package);

    $paused = $user->pauseSubscription();

    expect($paused)->not->toBeNull();
    expect($paused->status)->toBe(SubscriptionStatus::Paused);

    // A paused subscription is not "valid", so the subscriber is no longer
    // resolved as subscribed (README: Paused makes isValid() false).
    $user->clearSubscriptionCache();
    expect($user->subscribed())->toBeFalse();
});

it('scheduleDowngrade via the trait records a pending change and pendingChange() returns the target package', function () {
    $from = Package::factory()->create();
    $to = Package::factory()->create();
    $user = createUser();
    Tashil::subscription()->subscribe($user, $from);

    $result = $user->scheduleDowngrade($to);

    expect($result)->not->toBeNull();
    expect($result->hasPendingChange())->toBeTrue();

    $user->clearSubscriptionCache();
    expect($user->pendingChange())->not->toBeNull();
    expect($user->pendingChange()->id)->toBe($to->id);
});

it('pendingChange() returns null when no change is scheduled', function () {
    $package = Package::factory()->create();
    $user = createUser();
    Tashil::subscription()->subscribe($user, $package);

    expect($user->pendingChange())->toBeNull();
});

it('paused() returns false for an active subscriber', function () {
    $package = Package::factory()->create();
    $user = createUser();
    Tashil::subscription()->subscribe($user, $package);

    expect($user->paused())->toBeFalse();
});

it('pauseSubscription returns null when the subscriber has no subscription', function () {
    $user = createUser();

    expect($user->pauseSubscription())->toBeNull();
});

it('scheduleDowngrade returns null when the subscriber has no subscription', function () {
    $package = Package::factory()->create();
    $user = createUser();

    expect($user->scheduleDowngrade($package))->toBeNull();
});
