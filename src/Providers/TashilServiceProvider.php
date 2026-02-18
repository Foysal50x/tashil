<?php

namespace Foysal50x\Tashil\Providers;

use Foysal50x\Tashil\Contracts\FeatureRepositoryInterface;
use Foysal50x\Tashil\Contracts\InvoiceRepositoryInterface;
use Foysal50x\Tashil\Contracts\PackageRepositoryInterface;
use Foysal50x\Tashil\Contracts\SubscriptionItemRepositoryInterface;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Contracts\UsageLogRepositoryInterface;
use Foysal50x\Tashil\Managers\CacheManager;
use Foysal50x\Tashil\Repositories\Cache\CacheFeatureRepository;
use Foysal50x\Tashil\Repositories\Cache\CacheInvoiceRepository;
use Foysal50x\Tashil\Repositories\Cache\CachePackageRepository;
use Foysal50x\Tashil\Repositories\Cache\CacheSubscriptionItemRepository;
use Foysal50x\Tashil\Repositories\Cache\CacheSubscriptionRepository;
use Foysal50x\Tashil\Repositories\Cache\CacheUsageLogRepository;
use Foysal50x\Tashil\Repositories\EloquentFeatureRepository;
use Foysal50x\Tashil\Repositories\EloquentInvoiceRepository;
use Foysal50x\Tashil\Repositories\EloquentPackageRepository;
use Foysal50x\Tashil\Repositories\EloquentSubscriptionItemRepository;
use Foysal50x\Tashil\Repositories\EloquentSubscriptionRepository;
use Foysal50x\Tashil\Repositories\EloquentUsageLogRepository;
use Foysal50x\Tashil\Services\AnalyticsService;
use Foysal50x\Tashil\Services\BillingService;
use Foysal50x\Tashil\Services\SubscriptionService;
use Foysal50x\Tashil\Services\UsageService;
use Foysal50x\Tashil\Tashil;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class TashilServiceProvider extends ServiceProvider
{

    protected string $storeName = 'tashil';

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/tashil.php', 'tashil');

        $this->registerRedisConnection();
        $this->registerCacheStore();
        $this->registerRepositories();
        $this->registerServices();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../../config/tashil.php' => config_path('tashil.php'),
        ], 'tashil-config');

        // Publish migrations
        $this->publishes([
            __DIR__ . '/../../database/migrations' => database_path('migrations'),
        ], 'tashil-migrations');

        // Load migrations from package (always, not just in console)
        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Foysal50x\Tashil\Console\ProcessSubscriptionsCommand::class,
            ]);
        }

        \Foysal50x\Tashil\Models\Invoice::observe(\Foysal50x\Tashil\Observers\InvoiceObserver::class);
    }

    // ── Redis ───────────────────────────────────────────────────

    protected function registerRedisConnection(): void
    {
        $redis = Config::get('database.redis', []);

        $redis[$this->storeName] = array_merge(Config::get('tashil.redis', []), []);

        Config::set('database.redis', $redis);
    }

    // ── Cache ───────────────────────────────────────────────────

    protected function registerCacheStore(): void
    {
        $stores = Config::get('cache.stores', []);

        $store = $this->storeName;

        if (! isset($stores[$store])) {
            $driver = 'redis';
            $stores[$store] = [
                'driver'     => $driver,
                'connection' => $store,
                'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
            ];
        }

        Config::set('cache.stores', $stores);
    }

    // ── Repositories ────────────────────────────────────────────

    protected function registerRepositories(): void
    {
        $cacheEnabled = Config::get('tashil.cache.enabled', true);
        $cacheTtl = Config::get('tashil.cache.ttl', 3600);
        $cachePrefix = Config::get('tashil.cache.prefix', 'tashil');

        // Bind CacheManager as singleton
        $this->app->singleton(CacheManager::class, function ($app) {
            return new CacheManager($this->storeName);
        });

        // Subscription Repository
        $this->app->singleton(SubscriptionRepositoryInterface::class, function ($app) use ($cacheEnabled, $cachePrefix, $cacheTtl) {
            $eloquentRepo = new EloquentSubscriptionRepository();
            
            if ($cacheEnabled) {
                $cacheManager = $app->make(CacheManager::class);
                return new CacheSubscriptionRepository($eloquentRepo, $cacheManager, $cacheTtl, $cachePrefix);
            }

            return $eloquentRepo;
        });

        // Subscription Item Repository
        $this->app->singleton(SubscriptionItemRepositoryInterface::class, function ($app) use ($cacheEnabled, $cachePrefix, $cacheTtl) {
            $eloquentRepo = new EloquentSubscriptionItemRepository();
            
            if ($cacheEnabled) {
                $cacheManager = $app->make(CacheManager::class);
                return new CacheSubscriptionItemRepository($eloquentRepo, $cacheManager, $cacheTtl, $cachePrefix);
            }

            return $eloquentRepo;
        });

        // Invoice Repository
        $this->app->singleton(InvoiceRepositoryInterface::class, function ($app) use ($cacheEnabled, $cachePrefix, $cacheTtl) {
            $eloquentRepo = new EloquentInvoiceRepository();
            
            if ($cacheEnabled) {
                $cacheManager = $app->make(CacheManager::class);
                return new CacheInvoiceRepository($eloquentRepo, $cacheManager, $cacheTtl, $cachePrefix);
            }

            return $eloquentRepo;
        });

        // Package Repository
        $this->app->singleton(PackageRepositoryInterface::class, function () use ($cacheEnabled, $cacheTtl, $cachePrefix) {
            $eloquent = new EloquentPackageRepository();

            if ($cacheEnabled) {
                return new CachePackageRepository($eloquent, $this->app->make(CacheManager::class), $cacheTtl, $cachePrefix);
            }

            return $eloquent;
        });

        // Feature Repository
        $this->app->singleton(FeatureRepositoryInterface::class, function () use ($cacheEnabled, $cacheTtl, $cachePrefix) {
            $eloquent = new EloquentFeatureRepository();

            if ($cacheEnabled) {
                return new CacheFeatureRepository($eloquent, $this->app->make(CacheManager::class), $cacheTtl, $cachePrefix);
            }

            return $eloquent;
        });

        // Usage Log Repository
        $this->app->singleton(UsageLogRepositoryInterface::class, function () use ($cacheEnabled, $cacheTtl, $cachePrefix) {
            $eloquent = new EloquentUsageLogRepository();
            $usageTtl = (int) ($cacheTtl / 6); // Shorter TTL for usage data

            if ($cacheEnabled) {
                return new CacheUsageLogRepository($eloquent, $this->app->make(CacheManager::class), $usageTtl, $cachePrefix);
            }

            return $eloquent;
        });
    }

    // ── Services ────────────────────────────────────────────────

    protected function registerServices(): void
    {
        $this->app->singleton('tashil', function ($app) {
            return new Tashil(
                $app->make(SubscriptionService::class),
                $app->make(UsageService::class),
                $app->make(AnalyticsService::class),
                $app->make(BillingService::class)
            );
        });

        // Allow resolving via class name too
        $this->app->alias('tashil', Tashil::class);
    }
}
