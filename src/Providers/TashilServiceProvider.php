<?php

namespace Foysal50x\Tashil\Providers;

use Foysal50x\Tashil\Console\ApplyPendingChangesCommand;
use Foysal50x\Tashil\Console\ExpireSubscriptionsCommand;
use Foysal50x\Tashil\Console\ExpireTrialsCommand;
use Foysal50x\Tashil\Console\MarkTrialsEndingCommand;
use Foysal50x\Tashil\Console\ProcessDunningCommand;
use Foysal50x\Tashil\Console\RenewSubscriptionsCommand;
use Foysal50x\Tashil\Console\ResetQuotasCommand;
use Foysal50x\Tashil\Contracts\FeatureRepositoryInterface;
use Foysal50x\Tashil\Contracts\FeatureUsageRepositoryInterface;
use Foysal50x\Tashil\Contracts\InvoiceRepositoryInterface;
use Foysal50x\Tashil\Contracts\MeteredBilling;
use Foysal50x\Tashil\Contracts\PackageRepositoryInterface;
use Foysal50x\Tashil\Contracts\SubscriptionEventRepositoryInterface;
use Foysal50x\Tashil\Contracts\SubscriptionFeatureRepositoryInterface;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Contracts\UsageLogRepositoryInterface;
use Foysal50x\Tashil\Http\Middleware\EnsureFeature;
use Foysal50x\Tashil\Http\Middleware\EnsurePlan;
use Foysal50x\Tashil\Http\Middleware\EnsureSubscribed;
use Foysal50x\Tashil\Managers\CacheManager;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Transaction;
use Foysal50x\Tashil\Observers\InvoiceObserver;
use Foysal50x\Tashil\Observers\TransactionObserver;
use Foysal50x\Tashil\Repositories\Cache\CacheFeatureRepository;
use Foysal50x\Tashil\Repositories\Cache\CacheInvoiceRepository;
use Foysal50x\Tashil\Repositories\Cache\CachePackageRepository;
use Foysal50x\Tashil\Repositories\Cache\CacheSubscriptionRepository;
use Foysal50x\Tashil\Repositories\Cache\CacheUsageLogRepository;
use Foysal50x\Tashil\Repositories\EloquentFeatureRepository;
use Foysal50x\Tashil\Repositories\EloquentFeatureUsageRepository;
use Foysal50x\Tashil\Repositories\EloquentInvoiceRepository;
use Foysal50x\Tashil\Repositories\EloquentPackageRepository;
use Foysal50x\Tashil\Repositories\EloquentSubscriptionEventRepository;
use Foysal50x\Tashil\Repositories\EloquentSubscriptionFeatureRepository;
use Foysal50x\Tashil\Repositories\EloquentSubscriptionRepository;
use Foysal50x\Tashil\Repositories\EloquentUsageLogRepository;
use Foysal50x\Tashil\Services\AnalyticsService;
use Foysal50x\Tashil\Services\BillingService;
use Foysal50x\Tashil\Services\EventStore;
use Foysal50x\Tashil\Services\Providers\NullMeteredBilling;
use Foysal50x\Tashil\Services\SubscriptionService;
use Foysal50x\Tashil\Services\UsageService;
use Foysal50x\Tashil\Tashil;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class TashilServiceProvider extends ServiceProvider
{
    protected string $storeName = 'tashil';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/tashil.php', 'tashil');

        $this->registerRedisConnection();
        $this->registerCacheStore();
        $this->registerRepositories();
        $this->registerServices();
        $this->registerMeteredBilling();
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/tashil.php' => config_path('tashil.php'),
        ], 'tashil-config');

        $this->publishes([
            __DIR__ . '/../../database/migrations' => database_path('migrations'),
        ], 'tashil-migrations');

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                RenewSubscriptionsCommand::class,
                ExpireSubscriptionsCommand::class,
                ExpireTrialsCommand::class,
                MarkTrialsEndingCommand::class,
                ResetQuotasCommand::class,
                ApplyPendingChangesCommand::class,
                ProcessDunningCommand::class,
            ]);

            $this->registerSchedule();
        }

        Invoice::observe(InvoiceObserver::class);
        Transaction::observe(TransactionObserver::class);

        $this->registerMiddleware();
        $this->registerBladeDirectives();
    }

    protected function registerRedisConnection(): void
    {
        $redis = Config::get('database.redis', []);
        $redis[$this->storeName] = array_merge(Config::get('tashil.redis', []), []);
        Config::set('database.redis', $redis);
    }

    protected function registerCacheStore(): void
    {
        $stores = Config::get('cache.stores', []);
        $store = $this->storeName;

        if (! isset($stores[$store])) {
            $stores[$store] = [
                'driver'          => 'redis',
                'connection'      => $store,
                'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
            ];
        }

        Config::set('cache.stores', $stores);
    }

    protected function registerRepositories(): void
    {
        $cacheEnabled = Config::get('tashil.cache.enabled', true);
        $cacheTtl = Config::get('tashil.cache.ttl', 3600);
        $cachePrefix = Config::get('tashil.cache.prefix', 'tashil');

        $this->app->singleton(CacheManager::class, function () {
            return new CacheManager($this->storeName);
        });

        $this->app->singleton(SubscriptionRepositoryInterface::class, function (Application $app) use ($cacheEnabled, $cachePrefix, $cacheTtl) {
            $eloquent = new EloquentSubscriptionRepository;

            if ($cacheEnabled) {
                return new CacheSubscriptionRepository($eloquent, $app->make(CacheManager::class), $cacheTtl, $cachePrefix);
            }

            return $eloquent;
        });

        $this->app->singleton(InvoiceRepositoryInterface::class, function (Application $app) use ($cacheEnabled, $cachePrefix, $cacheTtl) {
            $eloquent = new EloquentInvoiceRepository;

            if ($cacheEnabled) {
                return new CacheInvoiceRepository($eloquent, $app->make(CacheManager::class), $cacheTtl, $cachePrefix);
            }

            return $eloquent;
        });

        $this->app->singleton(PackageRepositoryInterface::class, function (Application $app) use ($cacheEnabled, $cacheTtl, $cachePrefix) {
            $eloquent = new EloquentPackageRepository;

            if ($cacheEnabled) {
                return new CachePackageRepository($eloquent, $app->make(CacheManager::class), $cacheTtl, $cachePrefix);
            }

            return $eloquent;
        });

        $this->app->singleton(FeatureRepositoryInterface::class, function (Application $app) use ($cacheEnabled, $cacheTtl, $cachePrefix) {
            $eloquent = new EloquentFeatureRepository;

            if ($cacheEnabled) {
                return new CacheFeatureRepository($eloquent, $app->make(CacheManager::class), $cacheTtl, $cachePrefix);
            }

            return $eloquent;
        });

        $this->app->singleton(UsageLogRepositoryInterface::class, function (Application $app) use ($cacheEnabled, $cacheTtl, $cachePrefix) {
            $eloquent = new EloquentUsageLogRepository;
            $usageTtl = (int) ($cacheTtl / 6);

            if ($cacheEnabled) {
                return new CacheUsageLogRepository($eloquent, $app->make(CacheManager::class), $usageTtl, $cachePrefix);
            }

            return $eloquent;
        });

        // Hot-mutating data — not cached.
        $this->app->singleton(FeatureUsageRepositoryInterface::class, fn () => new EloquentFeatureUsageRepository);
        $this->app->singleton(SubscriptionFeatureRepositoryInterface::class, fn () => new EloquentSubscriptionFeatureRepository);
        $this->app->singleton(SubscriptionEventRepositoryInterface::class, fn () => new EloquentSubscriptionEventRepository);
    }

    protected function registerServices(): void
    {
        $this->app->singleton('tashil', function (Application $app) {
            return new Tashil(
                $app->make(SubscriptionService::class),
                $app->make(UsageService::class),
                $app->make(AnalyticsService::class),
                $app->make(BillingService::class),
                $app->make(EventStore::class),
            );
        });

        $this->app->alias('tashil', Tashil::class);
    }

    /**
     * Bind the default MeteredBilling. The Null implementation
     * throws on every call so that consuming a Metered feature without
     * a real provider configured fails loudly rather than silently.
     * Hosts override this binding in their own service provider when
     * they want Metered features to work.
     */
    protected function registerMeteredBilling(): void
    {
        $this->app->singletonIf(
            MeteredBilling::class,
            NullMeteredBilling::class,
        );
    }

    /**
     * Register the three route middleware (subscribed / plan / feature)
     * under configurable aliases. Hosts that already use one of the
     * default aliases for something else can rename via
     * tashil.middleware.aliases.
     */
    protected function registerMiddleware(): void
    {
        $aliases = Config::get('tashil.middleware.aliases', [
            'subscribed' => 'subscribed',
            'plan'       => 'plan',
            'feature'    => 'feature',
        ]);

        $router = $this->app->make(Router::class);

        $map = [
            'subscribed' => EnsureSubscribed::class,
            'plan'       => EnsurePlan::class,
            'feature'    => EnsureFeature::class,
        ];

        foreach ($map as $key => $class) {
            $alias = $aliases[$key] ?? $key;
            if ($alias === null || $alias === '') {
                continue;
            }
            $router->aliasMiddleware($alias, $class);
        }

        // Older Laravel versions expose the HTTP kernel directly; binding
        // the alias on the kernel keeps the package compatible with apps
        // that still resolve middleware through Kernel::$routeMiddleware.
        if ($this->app->bound(HttpKernelContract::class)) {
            $kernel = $this->app->make(HttpKernelContract::class);
            if (method_exists($kernel, 'getRouter')) {
                foreach ($map as $key => $class) {
                    $alias = $aliases[$key] ?? $key;
                    if ($alias === null || $alias === '') {
                        continue;
                    }
                    $kernel->getRouter()->aliasMiddleware($alias, $class);
                }
            }
        }
    }

    /**
     * Register Blade `@subscribed`, `@plan`, `@feature`, `@onTrial`
     * conditional directives. All four resolve the subscribable through
     * Tashil::resolveSubscribable(), so the same auth()->user() default
     * and `resolveSubscribableUsing` override that drive the middleware
     * also drive view rendering.
     */
    protected function registerBladeDirectives(): void
    {
        Blade::if('subscribed', function (): bool {
            $sub = app(Tashil::class)->resolveSubscribable()?->resolveSubscription();

            return $sub !== null && $sub->isValid();
        });

        Blade::if('plan', function (string $slug): bool {
            $sub = app(Tashil::class)->resolveSubscribable()?->resolveSubscription();

            return $sub !== null && $sub->isValid() && $sub->package?->slug === $slug;
        });

        Blade::if('feature', function (string $slug): bool {
            $sub = app(Tashil::class)->resolveSubscribable()?->resolveSubscription();
            if ($sub === null || ! $sub->isValid()) {
                return false;
            }

            return app('tashil')->usage()->check($sub, $slug);
        });

        Blade::if('onTrial', function (): bool {
            return app(Tashil::class)->resolveSubscribable()?->resolveSubscription()?->isOnTrial() ?? false;
        });
    }

    protected function registerSchedule(): void
    {
        if (! Config::get('tashil.schedule.enabled', true)) {
            return;
        }

        $this->app->booted(function () {
            $schedule = $this->app->make(Schedule::class);
            $overrides = Config::get('tashil.schedule.overrides', []);

            $defaults = [
                'tashil:renew-subscriptions'   => '5 0 * * *',
                'tashil:expire-trials'         => '*/30 * * * *',
                'tashil:mark-trials-ending'    => '55 7 * * *',
                'tashil:reset-quotas'          => '0 0 * * *',
                'tashil:apply-pending-changes' => '*/5 * * * *',
                'tashil:expire-subscriptions'  => '*/15 * * * *',
                'tashil:process-dunning'       => '*/30 * * * *',
            ];

            foreach ($defaults as $command => $cron) {
                $expression = $overrides[$command] ?? $cron;
                $schedule->command($command)->cron($expression)->onOneServer();
            }
        });
    }
}
