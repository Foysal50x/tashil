<?php

namespace Foysal50x\Tashil;

use Foysal50x\Tashil\Builders\FeatureBuilder;
use Foysal50x\Tashil\Builders\PackageBuilder;
use Foysal50x\Tashil\Services\AnalyticsService;
use Foysal50x\Tashil\Services\BillingService;
use Foysal50x\Tashil\Services\SubscriptionService;
use Foysal50x\Tashil\Services\UsageService;

class Tashil
{
    public function __construct(
        protected SubscriptionService $subscriptionService,
        protected UsageService $usageService,
        protected AnalyticsService $analyticsService,
        protected BillingService $billingService
    ) {}

    // ── Service accessors ───────────────────────────────────────

    public function subscription(): SubscriptionService
    {
        return $this->subscriptionService;
    }

    public function usage(): UsageService
    {
        return $this->usageService;
    }

    public function analytics(): AnalyticsService
    {
        return $this->analyticsService;
    }

    public function billing(): BillingService
    {
        return $this->billingService;
    }

    // ── Builder accessors ───────────────────────────────────────

    /**
     * Start building a new Feature.
     *
     * Usage: Tashil::feature('api-requests')->name('API Requests')->limit()->create();
     */
    public function feature(string $slug): FeatureBuilder
    {
        return new FeatureBuilder($slug);
    }

    /**
     * Start building a new Package.
     *
     * Usage: Tashil::package('pro')->name('Pro Plan')->price(29.99)->monthly()->create();
     */
    public function package(string $slug): PackageBuilder
    {
        return new PackageBuilder($slug);
    }
}
