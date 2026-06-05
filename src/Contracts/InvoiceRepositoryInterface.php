<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Contracts;

use Foysal50x\Tashil\Models\Invoice;
use Illuminate\Database\Eloquent\Collection;

interface InvoiceRepositoryInterface
{
    /**
     * Create a new invoice.
     */
    public function create(array $data): Invoice;

    /**
     * Find all invoices for given subscription IDs.
     */
    public function findBySubscriptionIds(array $subscriptionIds): Collection;

    /**
     * Pending invoices past their due date whose subscription is in a
     * dunning-eligible state (active / past_due / suspended). Drives
     * tashil:process-dunning.
     *
     * @return Collection<int, Invoice>
     */
    public function dueForDunning(\DateTimeInterface $moment): Collection;

    /**
     * Count pending invoices.
     */
    public function pendingCount(): int;

    /**
     * Count overdue invoices (pending + past due date).
     */
    public function overdueCount(): int;

    /**
     * Sum of all paid invoice amounts.
     */
    public function totalRevenue(): float;

    /**
     * Monthly revenue totals for the last N months.
     *
     * @return array<int, array{month: string, revenue: float}>
     */
    public function revenueByPeriod(int $months = 12): array;

    /**
     * Batched dashboard stats in a single query.
     *
     * @return array{total_revenue: float, pending_count: int, overdue_count: int}
     */
    public function dashboardStats(): array;

    /**
     * Invoice statistics grouped by package.
     *
     * @return array<int, array{package_id: int, pending_count: int, overdue_count: int}>
     */
    public function invoiceStatsByPackage(): array;
}
