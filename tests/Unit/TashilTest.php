<?php

use Foysal50x\Tashil\Builders\FeatureBuilder;
use Foysal50x\Tashil\Builders\PackageBuilder;
use Foysal50x\Tashil\Services\AnalyticsService;
use Foysal50x\Tashil\Services\BillingService;
use Foysal50x\Tashil\Services\SubscriptionService;
use Foysal50x\Tashil\Services\UsageService;
use Foysal50x\Tashil\Tashil;

it('returns the subscription service', function () {
    $tashil = app(Tashil::class);

    expect($tashil->subscription())->toBeInstanceOf(SubscriptionService::class);
});

it('returns the usage service', function () {
    $tashil = app(Tashil::class);

    expect($tashil->usage())->toBeInstanceOf(UsageService::class);
});

it('returns the analytics service', function () {
    $tashil = app(Tashil::class);

    expect($tashil->analytics())->toBeInstanceOf(AnalyticsService::class);
});

it('returns the billing service', function () {
    $tashil = app(Tashil::class);

    expect($tashil->billing())->toBeInstanceOf(BillingService::class);
});

it('returns a FeatureBuilder', function () {
    $tashil = app(Tashil::class);

    $builder = $tashil->feature('api-requests');

    expect($builder)->toBeInstanceOf(FeatureBuilder::class);
});

it('returns a PackageBuilder', function () {
    $tashil = app(Tashil::class);

    $builder = $tashil->package('pro');

    expect($builder)->toBeInstanceOf(PackageBuilder::class);
});
