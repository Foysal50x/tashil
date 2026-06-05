<?php

declare(strict_types=1);

use Foysal50x\Tashil\Http\Middleware\EnsureFeature;
use Foysal50x\Tashil\Http\Middleware\EnsurePlan;
use Foysal50x\Tashil\Http\Middleware\EnsureSubscribed;
use Foysal50x\Tashil\Tashil;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->loadMigrationsFrom(__DIR__ . '/../Fixtures/create_users_table.php');
});

afterEach(function () {
    app(Tashil::class)->forgetSubscribableResolver();
});

it('registers middleware under the default aliases', function () {
    $router = app(Router::class);
    $aliases = $router->getMiddleware();

    expect($aliases)->toHaveKey('subscribed');
    expect($aliases)->toHaveKey('plan');
    expect($aliases)->toHaveKey('feature');
    expect($aliases['subscribed'])->toBe(EnsureSubscribed::class);
    expect($aliases['plan'])->toBe(EnsurePlan::class);
    expect($aliases['feature'])->toBe(EnsureFeature::class);
});

it('routes using the alias string match the same handler as the class', function () {
    $user = createUser();
    app(Tashil::class)->resolveSubscribableUsing(fn () => $user);

    Route::middleware('subscribed')->get('/alias/sub', fn () => 'ok');
    $this->get('/alias/sub')->assertStatus(403);
});
