<?php

namespace Foysal50x\Tashil\Repositories;

use Foysal50x\Tashil\Contracts\InvoiceRepositoryInterface;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Support\Query\DateFmt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
use Tpetry\QueryExpressions\Function\Aggregate\CountFilter;
use Tpetry\QueryExpressions\Function\Aggregate\Sum;
use Tpetry\QueryExpressions\Function\Aggregate\SumFilter;
use Tpetry\QueryExpressions\Function\Conditional\Coalesce;
use Tpetry\QueryExpressions\Function\Time\Now;
use Tpetry\QueryExpressions\Language\Alias;
use Tpetry\QueryExpressions\Operator\Comparison\Equal;
use Tpetry\QueryExpressions\Operator\Comparison\LessThan;
use Tpetry\QueryExpressions\Operator\Comparison\NotIsNull;
use Tpetry\QueryExpressions\Operator\Logical\CondAnd;
use Tpetry\QueryExpressions\Value\Value;

class EloquentInvoiceRepository implements InvoiceRepositoryInterface
{
    public function create(array $data): Invoice
    {
        return Invoice::create($data);
    }

    public function findBySubscriptionIds(array $subscriptionIds): Collection
    {
        return Invoice::whereIn('subscription_id', $subscriptionIds)
            ->latest()
            ->get();
    }

    public function pendingCount(): int
    {
        return Invoice::where('status', InvoiceStatus::Pending)->count();
    }

    public function overdueCount(): int
    {
        return Invoice::where('status', InvoiceStatus::Pending)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->count();
    }

    public function totalRevenue(): float
    {
        return (float) Invoice::where('status', InvoiceStatus::Paid)->sum('amount');
    }

    public function revenueByPeriod(int $months = 12): array
    {
        $since = now()->subMonths($months)->startOfMonth();

        return Invoice::query()
            ->where('status', InvoiceStatus::Paid)
            ->where('paid_at', '>=', $since)
            ->select(
                new Alias(new DateFmt('paid_at', 'Y-m'), 'month'),
                new Alias(new Sum('amount'), 'revenue')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => ['month' => $row->month, 'revenue' => round((float) $row->revenue, 2)])
            ->toArray();
    }

    public function dashboardStats(): array
    {
        $paid    = new Value(InvoiceStatus::Paid->value);
        $pending = new Value(InvoiceStatus::Pending->value);

        $row = Invoice::query()
            ->select([
                new Alias(
                    new Coalesce([new SumFilter('amount', new Equal('status', $paid)), new Value(0)]),
                    'total_revenue'
                ),
                new Alias(
                    new CountFilter(new Equal('status', $pending)),
                    'pending_count'
                ),
                new Alias(
                    new CountFilter(new CondAnd(
                        new Equal('status', $pending),
                        new CondAnd(
                            new NotIsNull('due_date'),
                            new LessThan('due_date', new Now)
                        )
                    )),
                    'overdue_count'
                ),
            ])
            ->first();

        return [
            'total_revenue'  => round((float) $row->total_revenue, 2),
            'pending_count'  => (int) $row->pending_count,
            'overdue_count'  => (int) $row->overdue_count,
        ];
    }

    public function invoiceStatsByPackage(): array
    {
        $prefix = Config::get('tashil.database.prefix', 'tashil_');
        $subscriptionsTable = $prefix . Config::get('tashil.database.tables.subscriptions', 'subscriptions');
        $packagesTable = $prefix . Config::get('tashil.database.tables.packages', 'packages');
        $invoicesTable = $prefix . Config::get('tashil.database.tables.invoices', 'invoices');

        $pending = new Value(InvoiceStatus::Pending->value);

        return Invoice::query()
            ->join($subscriptionsTable, "{$invoicesTable}.subscription_id", '=', "{$subscriptionsTable}.id")
            ->join($packagesTable, "{$subscriptionsTable}.package_id", '=', "{$packagesTable}.id")
            ->select([
                "{$packagesTable}.id as package_id",
                new Alias(
                    new CountFilter(new Equal("{$invoicesTable}.status", $pending)),
                    'pending_count'
                ),
                new Alias(
                    new CountFilter(new CondAnd(
                        new Equal("{$invoicesTable}.status", $pending),
                        new CondAnd(
                            new NotIsNull("{$invoicesTable}.due_date"),
                            new LessThan("{$invoicesTable}.due_date", new Now)
                        )
                    )),
                    'overdue_count'
                ),
            ])
            ->groupBy("{$packagesTable}.id")
            ->get()
            ->map(fn ($row) => [
                'package_id'    => (int) $row->package_id,
                'pending_count' => (int) $row->pending_count,
                'overdue_count' => (int) $row->overdue_count,
            ])
            ->toArray();
    }
}
