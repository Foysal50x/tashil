<?php

namespace Foysal50x\Tashil\Repositories\Cache;

use Foysal50x\Tashil\Contracts\InvoiceRepositoryInterface;
use Foysal50x\Tashil\Managers\CacheManager;
use Foysal50x\Tashil\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;

/**
 * @property InvoiceRepositoryInterface $repository
 */
class CacheInvoiceRepository extends BaseCacheRepository implements InvoiceRepositoryInterface
{
    public function __construct(
        InvoiceRepositoryInterface $repository,
        CacheManager $cacheManager,
        int $cacheTtl,
        string $cachePrefix,
    ) {
        parent::__construct($repository, $cacheManager, $cacheTtl, $cachePrefix);
    }

    public function create(array $data): Invoice
    {
        $result = $this->repository->create($data);

        // Invalidate cached invoices for this subscription
        if (isset($data['subscription_id'])) {
            $this->forget("invoices:sub:{$data['subscription_id']}");
        }

        return $result;
    }

    public function findBySubscriptionIds(array $subscriptionIds): Collection
    {
        // Don't cache multi-subscription queries (too complex key; rare operation)
        return $this->repository->findBySubscriptionIds($subscriptionIds);
    }

    public function pendingCount(): int
    {
        $key = 'invoice:aggregate:pending_count';

        return $this->remember($key, fn () => $this->repository->pendingCount());
    }

    public function overdueCount(): int
    {
        return $this->repository->overdueCount();
    }

    public function totalRevenue(): float
    {
        $key = 'invoice:aggregate:total_revenue';

        return $this->remember($key, fn () => $this->repository->totalRevenue());
    }

    public function revenueByPeriod(int $months = 12): array
    {
        return $this->repository->revenueByPeriod($months);
    }

    public function dashboardStats(): array
    {
        $key = 'invoice:aggregate:dashboard_stats';

        return $this->remember($key, fn () => $this->repository->dashboardStats());
    }

    public function invoiceStatsByPackage(): array
    {
        return $this->repository->invoiceStatsByPackage();
    }
}
