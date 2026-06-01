<?php

namespace Foysal50x\Tashil;

use Closure;
use Foysal50x\Tashil\Builders\FeatureBuilder;
use Foysal50x\Tashil\Builders\PackageBuilder;
use Foysal50x\Tashil\Contracts\Subscribable;
use Foysal50x\Tashil\Services\AnalyticsService;
use Foysal50x\Tashil\Services\BillingService;
use Foysal50x\Tashil\Services\EventStore;
use Foysal50x\Tashil\Services\SubscriptionService;
use Foysal50x\Tashil\Services\UsageService;
use Illuminate\Support\Facades\Auth;

class Tashil
{
    /**
     * Resolver used by middleware and Blade directives to pick the
     * subscribable for the current request. Defaults to auth()->user(),
     * which works for single-user-per-request apps. Multi-tenant apps
     * override with Tashil::resolveSubscribableUsing(fn () => Team::current()).
     *
     * @var (Closure(): ?Subscribable)|null
     */
    protected static ?Closure $subscribableResolver = null;

    public function __construct(
        protected SubscriptionService $subscriptionService,
        protected UsageService $usageService,
        protected AnalyticsService $analyticsService,
        protected BillingService $billingService,
        protected EventStore $eventStore,
    ) {}

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

    public function events(): EventStore
    {
        return $this->eventStore;
    }

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

    /**
     * Register a callable that returns the Subscribable for the current
     * request. Override in your AppServiceProvider when the subscribable
     * isn't the authenticated user — e.g. a tenant or team.
     *
     * Tashil::resolveSubscribableUsing(fn () => Team::current());
     */
    public function resolveSubscribableUsing(Closure $resolver): static
    {
        static::$subscribableResolver = $resolver;

        return $this;
    }

    /**
     * Resolve the Subscribable for the current request. Returns null if
     * no subscribable is bound (e.g. unauthenticated request, or the
     * authenticated user doesn't implement the contract).
     */
    public function resolveSubscribable(): ?Subscribable
    {
        $resolved = static::$subscribableResolver !== null
            ? (static::$subscribableResolver)()
            : Auth::user();

        return $resolved instanceof Subscribable ? $resolved : null;
    }

    /**
     * Reset the resolver. Mostly useful in tests between cases.
     */
    public function forgetSubscribableResolver(): static
    {
        static::$subscribableResolver = null;

        return $this;
    }
}
