<?php

namespace Foysal50x\Tashil\Repositories;

use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Support\Query\DateFmt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tpetry\QueryExpressions\Function\Aggregate\Count;
use Tpetry\QueryExpressions\Function\Aggregate\CountFilter;
use Tpetry\QueryExpressions\Function\Aggregate\Sum;
use Tpetry\QueryExpressions\Function\Aggregate\SumFilter;
use Tpetry\QueryExpressions\Function\Conditional\Coalesce;
use Tpetry\QueryExpressions\Language\Alias;
use Tpetry\QueryExpressions\Language\CaseGroup;
use Tpetry\QueryExpressions\Language\CaseRule;
use Tpetry\QueryExpressions\Operator\Arithmetic\Divide;
use Tpetry\QueryExpressions\Operator\Arithmetic\Multiply;
use Tpetry\QueryExpressions\Operator\Comparison\Equal;
use Tpetry\QueryExpressions\Operator\Comparison\NotIsNull;
use Tpetry\QueryExpressions\Operator\Logical\CondAnd;
use Tpetry\QueryExpressions\Operator\Logical\CondOr;
use Tpetry\QueryExpressions\Value\Value;

class EloquentSubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function create(array $data): Subscription
    {
        return Subscription::create($data);
    }

    public function update(Subscription $subscription, array $data): Subscription
    {
        $subscription->update($data);

        return $subscription->refresh();
    }

    public function findById(int $id): ?Subscription
    {
        return Subscription::find($id);
    }

    public function findValidForSubscriber(Model $subscriber): ?Subscription
    {
        return $subscriber->morphMany(Subscription::class, 'subscriber')
            ->valid()
            ->latest('starts_at')
            ->first();
    }

    public function subscriberHasValidSubscription(Model $subscriber, Package|string|null $package = null): bool
    {
        // Use Eloquent directly to avoid connection/table mapping issues
        $query = Subscription::query()
            ->where('subscriber_type', $subscriber->getMorphClass())
            ->where('subscriber_id', $subscriber->getKey())
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::OnTrial]);
            
        if ($package) {
            $slug = $package instanceof Package ? $package->slug : $package;
            $query->whereHas('package', function ($q) use ($slug) {
                $q->where('slug', $slug);
            });
        }
        
        return $query->exists();
    }

    public function findCancelledResumable(Model $subscriber): ?Subscription
    {
        return $subscriber->morphMany(Subscription::class, 'subscriber')
            ->where('status', SubscriptionStatus::Cancelled)
            ->whereNotNull('ends_at')
            ->where('ends_at', '>', now())
            ->latest()
            ->first();
    }

    public function getExpiringSubscriptions(\DateTimeInterface $date, ?bool $autoRenew = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = Subscription::query()
            ->whereDate('ends_at', $date)
            ->where('status', SubscriptionStatus::Active);

        if (! is_null($autoRenew)) {
            $query->where('auto_renew', $autoRenew);
        }

        return $query->get();
    }

    public function syncFeatureItems(Subscription $subscription, Package $package): void
    {
        foreach ($package->features as $feature) {
            if (! ($feature->pivot->is_available ?? true)) {
                continue;
            }

            $subscription->items()->create([
                'feature_id' => $feature->id,
                'value'      => $feature->pivot->value,
                'usage'      => 0,
            ]);
        }
    }

    public function activeCount(): int
    {
        return Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::OnTrial])
            ->count();
    }

    public function churnedCount(\DateTimeInterface $since): int
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::Cancelled)
            ->where('cancelled_at', '>=', $since)
            ->count();
    }

    public function totalCountInPeriod(\DateTimeInterface $since): int
    {
        return Subscription::query()
            ->where('created_at', '<=', now())
            ->where(function ($q) use ($since) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $since);
            })
            ->count();
    }

    public function churnTrend(int $months = 12, int $windowDays = 30): array
    {
        $earliestWindow = now()->subMonths($months - 1)->startOfMonth()->subDays($windowDays);

        // Query 1: all cancelled dates in the entire range
        $cancelledDates = Subscription::query()
            ->where('status', SubscriptionStatus::Cancelled)
            ->where('cancelled_at', '>=', $earliestWindow)
            ->pluck('cancelled_at')
            ->map(fn ($d) => \Carbon\Carbon::parse($d));

        // Query 2: subscription boundaries (created_at + ends_at) for all subs that could be relevant
        $boundaries = Subscription::query()
            ->where('created_at', '<=', now())
            ->where(function ($q) use ($earliestWindow) {
                $q->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $earliestWindow);
            })
            ->select('created_at', 'ends_at')
            ->get();

        // Compute per-month churn in PHP (0 queries)
        $result = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $endOfMonth = now()->subMonths($i)->endOfMonth();
            $startOfWindow = $endOfMonth->copy()->subDays($windowDays);

            // Total subs that existed during this window
            $total = $boundaries->filter(function ($row) use ($startOfWindow) {
                $created = \Carbon\Carbon::parse($row->created_at);
                if ($created->gt(now())) {
                    return false;
                }
                if ($row->ends_at === null) {
                    return true;
                }

                return \Carbon\Carbon::parse($row->ends_at)->gte($startOfWindow);
            })->count();

            // Churned in this window
            $churned = $total > 0
                ? $cancelledDates->filter(fn ($d) => $d->gte($startOfWindow))->count()
                : 0;

            $rate = $total > 0 ? round(($churned / $total) * 100, 2) : 0.0;

            $result[] = [
                'month'      => $endOfMonth->format('Y-m'),
                'churn_rate' => $rate,
            ];
        }

        return $result;
    }

    public function calculateMRR(): float
    {
        $prefix = Config::get('tashil.database.prefix', 'tashil_');
        $subscriptionsTable = $prefix . Config::get('tashil.database.tables.subscriptions', 'subscriptions');
        $packagesTable = $prefix . Config::get('tashil.database.tables.packages', 'packages');

        return (float) Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::OnTrial])
            ->join($packagesTable, "{$subscriptionsTable}.package_id", '=', "{$packagesTable}.id")
            ->sum(new CaseGroup([
                new CaseRule(
                    new Divide("{$packagesTable}.price", "{$packagesTable}.billing_interval"),
                    new Equal("{$packagesTable}.billing_period", new Value('month'))
                ),
                new CaseRule(
                    new Divide("{$packagesTable}.price", new Multiply(new Value(12), "{$packagesTable}.billing_interval")),
                    new Equal("{$packagesTable}.billing_period", new Value('year'))
                ),
                new CaseRule(
                    new Divide(new Multiply("{$packagesTable}.price", new Value(4.33)), "{$packagesTable}.billing_interval"),
                    new Equal("{$packagesTable}.billing_period", new Value('week'))
                ),
                new CaseRule(
                    new Divide(new Multiply("{$packagesTable}.price", new Value(30)), "{$packagesTable}.billing_interval"),
                    new Equal("{$packagesTable}.billing_period", new Value('day'))
                ),
                new CaseRule(new Value(0), new Equal("{$packagesTable}.billing_period", new Value('lifetime'))),
            ], new Value(0)));
    }

    public function totalCount(): int
    {
        return Subscription::count();
    }

    public function countByStatus(): array
    {
        return Subscription::query()
            ->select('status', new Alias(new Count('*'), 'count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    public function trialConversionRate(): float
    {
        $totalTrials = Subscription::query()
            ->whereNotNull('trial_ends_at')
            ->count();

        if ($totalTrials === 0) {
            return 0.0;
        }

        $converted = Subscription::query()
            ->whereNotNull('trial_ends_at')
            ->where('status', SubscriptionStatus::Active)
            ->count();

        return round(($converted / $totalTrials) * 100, 2);
    }

    public function newSubscriptionsPerPeriod(int $months = 12): array
    {
        $since = now()->subMonths($months)->startOfMonth();

        return Subscription::query()
            ->where('created_at', '>=', $since)
            ->select(
                new Alias(new DateFmt('created_at', 'Y-m'), 'month'),
                new Alias(new Count('*'), 'count')
            )
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn ($row) => ['month' => $row->month, 'count' => (int) $row->count])
            ->toArray();
    }

    public function subscribersByPackage(): array
    {
        $prefix = Config::get('tashil.database.prefix', 'tashil_');
        $subscriptionsTable = $prefix . Config::get('tashil.database.tables.subscriptions', 'subscriptions');
        $packagesTable = $prefix . Config::get('tashil.database.tables.packages', 'packages');

        return Subscription::query()
            ->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::OnTrial])
            ->join($packagesTable, "{$subscriptionsTable}.package_id", '=', "{$packagesTable}.id")
            ->select(
                "{$packagesTable}.id as package_id",
                "{$packagesTable}.name as package_name",
                new Alias(new Count('*'), 'count')
            )
            ->groupBy("{$packagesTable}.id", "{$packagesTable}.name")
            ->get()
            ->map(fn ($row) => [
                'package_id'   => (int) $row->package_id,
                'package_name' => $row->package_name,
                'count'        => (int) $row->count,
            ])
            ->toArray();
    }

    public function revenueByPackage(): array
    {
        $prefix = Config::get('tashil.database.prefix', 'tashil_');
        $subscriptionsTable = $prefix . Config::get('tashil.database.tables.subscriptions', 'subscriptions');
        $packagesTable = $prefix . Config::get('tashil.database.tables.packages', 'packages');
        $invoicesTable = $prefix . Config::get('tashil.database.tables.invoices', 'invoices');

        return Subscription::query()
            ->join($packagesTable, "{$subscriptionsTable}.package_id", '=', "{$packagesTable}.id")
            ->join($invoicesTable, "{$invoicesTable}.subscription_id", '=', "{$subscriptionsTable}.id")
            ->where("{$invoicesTable}.status", 'paid')
            ->select(
                "{$packagesTable}.id as package_id",
                "{$packagesTable}.name as package_name",
                new Alias(new Sum("{$invoicesTable}.amount"), 'revenue')
            )
            ->groupBy("{$packagesTable}.id", "{$packagesTable}.name")
            ->get()
            ->map(fn ($row) => [
                'package_id'   => (int) $row->package_id,
                'package_name' => $row->package_name,
                'revenue'      => round((float) $row->revenue, 2),
            ])
            ->toArray();
    }

    public function dashboardStats(): array
    {
        $prefix = Config::get('tashil.database.prefix', 'tashil_');
        $subscriptionsTable = $prefix . Config::get('tashil.database.tables.subscriptions', 'subscriptions');
        $packagesTable = $prefix . Config::get('tashil.database.tables.packages', 'packages');

        $active    = new Value(SubscriptionStatus::Active->value);
        $onTrial   = new Value(SubscriptionStatus::OnTrial->value);
        $cancelled = new Value(SubscriptionStatus::Cancelled->value);
        $expired   = new Value(SubscriptionStatus::Expired->value);

        // Filter: status is active OR on_trial
        $activeFilter = new CondOr(
            new Equal("{$subscriptionsTable}.status", $active),
            new Equal("{$subscriptionsTable}.status", $onTrial)
        );

        // MRR calculation per billing period — fully cross-DB
        $mrrCase = new CaseGroup([
            new CaseRule(
                new Divide("{$packagesTable}.price", "{$packagesTable}.billing_interval"),
                new Equal("{$packagesTable}.billing_period", new Value('month'))
            ),
            new CaseRule(
                new Divide("{$packagesTable}.price", new Multiply(new Value(12), "{$packagesTable}.billing_interval")),
                new Equal("{$packagesTable}.billing_period", new Value('year'))
            ),
            new CaseRule(
                new Divide(new Multiply("{$packagesTable}.price", new Value(4.33)), "{$packagesTable}.billing_interval"),
                new Equal("{$packagesTable}.billing_period", new Value('week'))
            ),
            new CaseRule(
                new Divide(new Multiply("{$packagesTable}.price", new Value(30)), "{$packagesTable}.billing_interval"),
                new Equal("{$packagesTable}.billing_period", new Value('day'))
            ),
            new CaseRule(new Value(0), new Equal("{$packagesTable}.billing_period", new Value('lifetime'))),
        ], new Value(0));

        $row = Subscription::query()
            ->leftJoin($packagesTable, "{$subscriptionsTable}.package_id", '=', "{$packagesTable}.id")
            ->select([
                new Alias(new Count('*'), 'total'),
                new Alias(new CountFilter($activeFilter), 'active'),
                new Alias(new CountFilter(new Equal("{$subscriptionsTable}.status", $onTrial)), 'on_trial'),
                new Alias(new CountFilter(new Equal("{$subscriptionsTable}.status", $cancelled)), 'cancelled'),
                new Alias(new CountFilter(new Equal("{$subscriptionsTable}.status", $expired)), 'expired'),
                new Alias(new CountFilter(new NotIsNull("{$subscriptionsTable}.trial_ends_at")), 'total_trials'),
                new Alias(
                    new CountFilter(new CondAnd(
                        new NotIsNull("{$subscriptionsTable}.trial_ends_at"),
                        new Equal("{$subscriptionsTable}.status", $active)
                    )),
                    'converted_trials'
                ),
                new Alias(new Coalesce([new SumFilter($mrrCase, $activeFilter), new Value(0)]), 'mrr'),
            ])
            ->first();

        $totalTrials     = (int) $row->total_trials;
        $convertedTrials = (int) $row->converted_trials;
        $trialConversionRate = $totalTrials > 0
            ? round(($convertedTrials / $totalTrials) * 100, 2)
            : 0.0;

        return [
            'total'                 => (int) $row->total,
            'active'                => (int) $row->active,
            'on_trial'              => (int) $row->on_trial,
            'cancelled'             => (int) $row->cancelled,
            'expired'               => (int) $row->expired,
            'trial_conversion_rate' => $trialConversionRate,
            'mrr'                   => round((float) $row->mrr, 2),
        ];
    }

    public function analyticsByPackage(): array
    {
        $prefix = Config::get('tashil.database.prefix', 'tashil_');
        $subscriptionsTable = $prefix . Config::get('tashil.database.tables.subscriptions', 'subscriptions');
        $packagesTable = $prefix . Config::get('tashil.database.tables.packages', 'packages');
        $invoicesTable = $prefix . Config::get('tashil.database.tables.invoices', 'invoices');

        $active    = new Value(SubscriptionStatus::Active->value);
        $onTrial   = new Value(SubscriptionStatus::OnTrial->value);
        $cancelled = new Value(SubscriptionStatus::Cancelled->value);

        // Filter: status is active OR on_trial
        $activeFilter = new CondOr(
            new Equal("{$subscriptionsTable}.status", $active),
            new Equal("{$subscriptionsTable}.status", $onTrial)
        );

        // MRR calculation per billing period
        $mrrCase = new CaseGroup([
            new CaseRule(
                new Divide("{$packagesTable}.price", "{$packagesTable}.billing_interval"),
                new Equal("{$packagesTable}.billing_period", new Value('month'))
            ),
            new CaseRule(
                new Divide("{$packagesTable}.price", new Multiply(new Value(12), "{$packagesTable}.billing_interval")),
                new Equal("{$packagesTable}.billing_period", new Value('year'))
            ),
            new CaseRule(
                new Divide(new Multiply("{$packagesTable}.price", new Value(4.33)), "{$packagesTable}.billing_interval"),
                new Equal("{$packagesTable}.billing_period", new Value('week'))
            ),
            new CaseRule(
                new Divide(new Multiply("{$packagesTable}.price", new Value(30)), "{$packagesTable}.billing_interval"),
                new Equal("{$packagesTable}.billing_period", new Value('day'))
            ),
            new CaseRule(new Value(0), new Equal("{$packagesTable}.billing_period", new Value('lifetime'))),
        ], new Value(0));

        $paid = new Value('paid');

        $rows = Subscription::query()
            ->join($packagesTable, "{$subscriptionsTable}.package_id", '=', "{$packagesTable}.id")
            ->leftJoin($invoicesTable, "{$invoicesTable}.subscription_id", '=', "{$subscriptionsTable}.id")
            ->select([
                "{$packagesTable}.id as package_id",
                "{$packagesTable}.name as package_name",
                new Alias(new Count('*'), 'total_subscribers'),
                new Alias(new CountFilter($activeFilter), 'active_subscribers'),
                new Alias(new CountFilter(new Equal("{$subscriptionsTable}.status", $cancelled)), 'cancelled_count'),
                new Alias(new CountFilter(new NotIsNull("{$subscriptionsTable}.trial_ends_at")), 'total_trials'),
                new Alias(
                    new CountFilter(new CondAnd(
                        new NotIsNull("{$subscriptionsTable}.trial_ends_at"),
                        new Equal("{$subscriptionsTable}.status", $active)
                    )),
                    'converted_trials'
                ),
                new Alias(new Coalesce([new SumFilter($mrrCase, $activeFilter), new Value(0)]), 'mrr'),
                new Alias(
                    new Coalesce([new SumFilter("{$invoicesTable}.amount", new Equal("{$invoicesTable}.status", $paid)), new Value(0)]),
                    'total_revenue'
                ),
            ])
            ->groupBy("{$packagesTable}.id", "{$packagesTable}.name")
            ->get();

        return $rows->map(function ($row) {
            $active = (int) $row->active_subscribers;
            $mrr    = round((float) $row->mrr, 2);

            $totalTrials     = (int) $row->total_trials;
            $convertedTrials = (int) $row->converted_trials;
            $trialConversionRate = $totalTrials > 0
                ? round(($convertedTrials / $totalTrials) * 100, 2)
                : 0.0;

            return [
                'package_id'            => (int) $row->package_id,
                'package_name'          => $row->package_name,
                'total_subscribers'     => (int) $row->total_subscribers,
                'active_subscribers'    => $active,
                'cancelled_count'       => (int) $row->cancelled_count,
                'mrr'                   => $mrr,
                'average_mrr'           => $active > 0 ? round($mrr / $active, 2) : 0.0,
                'trial_conversion_rate' => $trialConversionRate,
                'total_revenue'         => round((float) $row->total_revenue, 2),
            ];
        })->toArray();
    }
}
