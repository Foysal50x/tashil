<?php

namespace Foysal50x\Tashil\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static \Foysal50x\Tashil\Services\SubscriptionService subscription()
 * @method static \Foysal50x\Tashil\Services\UsageService usage()
 * @method static \Foysal50x\Tashil\Services\AnalyticsService analytics()
 * @method static \Foysal50x\Tashil\Services\BillingService billing()
 * @method static \Foysal50x\Tashil\Builders\FeatureBuilder feature(string $slug)
 * @method static \Foysal50x\Tashil\Builders\PackageBuilder package(string $slug)
 *
 * @see \Foysal50x\Tashil\Tashil
 */
class Tashil extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'tashil';
    }
}
