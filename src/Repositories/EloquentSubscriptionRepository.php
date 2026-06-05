<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Repositories;

use Carbon\Carbon;
use Foysal50x\Tashil\Contracts\Subscribable;
use Foysal50x\Tashil\Contracts\SubscriptionRepositoryInterface;
use Foysal50x\Tashil\Enums\FeatureType;
use Foysal50x\Tashil\Enums\ResetPeriod;
use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Foysal50x\Tashil\Models\SubscriptionFeature;
use Foysal50x\Tashil\Support\Query\DateFmt;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Config;
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

    public function findValidForSubscriber(Subscribable $subscriber): ?Subscription
    {
        return $subscriber->subscriptions()
            ->valid()
            ->latest('starts_at')
            ->first();
    }

    public function subscriberHasValidSubscription(Subscribable $subscriber, Package|string|null $package = null): bool
    {
        $query = Subscription::query()
            ->where('subscriber_type', $subscriber->getSubscriberType())
            ->where('subscriber_id', $subscriber->getSubscriberKey())
            ->valid();

        if ($package) {
            $slug = $package instanceof Package ? $package->slug : $package;
            $query->whereHas('package', function ($q) use ($slug) {
                $q->where('slug', $slug);
            });
        }

        return $query->exists();
    }

    public function hasLiveSubscription(Subscribable $subscriber): bool
    {
        return Subscription::query()
            ->where('subscriber_type', $subscriber->getSubscriberType())
            ->where('subscriber_id', $subscriber->getSubscriberKey())
            ->whereIn('status', [
                SubscriptionStatus::Active,
                SubscriptionStatus::OnTrial,
                SubscriptionStatus::Pending,
                SubscriptionStatus::PastDue,
                SubscriptionStatus::PendingCancellation,
                SubscriptionStatus::Paused,
                SubscriptionStatus::Suspended,
            ])
            ->exists();
    }

    public function findCancelledResumable(Subscribable $subscriber): ?Subscription
    {
        return $subscriber->subscriptions()
            ->where('status', SubscriptionStatus::PendingCancellation)
            ->where(function ($q) {
                $q->whereNull('cancellation_effective_at')
                    ->orWhere('cancellation_effective_at', '>', now());
            })
            ->latest()
            ->first();
    }

    public function dueForRenewal(\DateTimeInterface $moment): Collection
    {
        // Active only — never OnTrial. Trials are billed at conversion
        // (convertTrial issues the first invoice), so the renewal cron must
        // not bill a subscription whose trial period merely outlived the
        // first billing window.
        return Subscription::query()
            ->where('status', SubscriptionStatus::Active)
            ->where('auto_renew', true)
            ->whereNotNull('current_period_end')
            ->where('current_period_end', '<=', $moment)
            ->get();
    }

    public function dueForExpiration(\DateTimeInterface $moment): Collection
    {
        return Subscription::query()
            ->where(function ($q) use ($moment) {
                $q->where(function ($q) use ($moment) {
                    $q->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::OnTrial])
                        ->where('auto_renew', false)
                        ->whereNotNull('ends_at')
                        ->where('ends_at', '<=', $moment);
                })->orWhere(function ($q) use ($moment) {
                    $q->where('status', SubscriptionStatus::PendingCancellation)
                        ->whereNotNull('cancellation_effective_at')
                        ->where('cancellation_effective_at', '<=', $moment);
                });
            })
            ->get();
    }

    public function dueForTrialExpiration(\DateTimeInterface $moment): Collection
    {
        return Subscription::query()
            ->where('status', SubscriptionStatus::OnTrial)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $moment)
            ->whereNull('trial_converted_at')
            ->get();
    }

    public function trialsEndingSoon(\DateTimeInterface $now, int $warnDays): Collection
    {
        $cutoff = Carbon::instance($now)->copy()->addDays(max(0, $warnDays));

        return Subscription::query()
            ->where('status', SubscriptionStatus::OnTrial)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', $now)
            ->where('trial_ends_at', '<=', $cutoff)
            ->whereNull('trial_converted_at')
            ->get();
    }

    public function dueForPendingChange(\DateTimeInterface $moment): Collection
    {
        return Subscription::query()
            ->whereNotNull('pending_package_id')
            ->whereNotNull('pending_change_at')
            ->where('pending_change_at', '<=', $moment)
            ->get();
    }

    public function syncFeatures(Subscription $subscription, Package $package): void
    {
        $now = now();

        foreach ($package->features as $feature) {
            if (! ($feature->pivot->is_available ?? true)) {
                continue;
            }

            $value = $feature->pivot->value;
            $reset = $feature->reset_period ?? ResetPeriod::Never;

            $subscription->subscriptionFeatures()->create([
                'feature_id'    => $feature->id,
                'feature_slug'  => $feature->slug,
                'feature_type'  => $feature->type,
                'value'         => $value,
                'reset_period'  => $reset,
                'added_at'      => $now,
                'superseded_at' => null,
            ]);

            // Counter row. limit_value is set ONLY for Limit features —
            // for Metered the pivot value holds unit_price, not a cap, so
            // the counter stays unlimited and atomicIncrement always
            // succeeds; the gate is the MeteredBilling charge.
            $periodEnd = EloquentFeatureUsageRepository::nextPeriodEnd($reset, $now, $now);
            $limitValue = ($feature->type === FeatureType::Limit && is_numeric($value))
                ? (float) $value
                : null;

            $subscription->featureUsages()->create([
                'feature_id'   => $feature->id,
                'usage'        => 0,
                'limit_value'  => $limitValue,
                'reset_period' => $reset,
                'period_start' => $now,
                'period_end'   => $periodEnd,
            ]);
        }
    }

    public function resyncFeatures(Subscription $subscription, Package $package, bool $carryUsage = true): void
    {
        $now = now();

        // Supersede the currently-active snapshot rows. Immutable model:
        // only superseded_at (and updated_at) may change.
        $subscription->currentFeatures()->get()->each(function (SubscriptionFeature $snapshot) use ($now) {
            $snapshot->update(['superseded_at' => $now]);
        });

        $existingUsages = $subscription->featureUsages()->get()->keyBy('feature_id');

        foreach ($package->features as $feature) {
            if (! ($feature->pivot->is_available ?? true)) {
                continue;
            }

            $value = $feature->pivot->value;
            $reset = $feature->reset_period ?? ResetPeriod::Never;

            $subscription->subscriptionFeatures()->create([
                'feature_id'    => $feature->id,
                'feature_slug'  => $feature->slug,
                'feature_type'  => $feature->type,
                'value'         => $value,
                'reset_period'  => $reset,
                'added_at'      => $now,
                'superseded_at' => null,
            ]);

            $limitValue = ($feature->type === FeatureType::Limit && is_numeric($value))
                ? (float) $value
                : null;

            $existing = $existingUsages->get($feature->id);

            if ($existing && $carryUsage) {
                // Keep the usage value; update the cap + reset cadence to the
                // new plan. Period window is preserved.
                $existing->update([
                    'limit_value'  => $limitValue,
                    'reset_period' => $reset,
                ]);
            } else {
                // New counter (or a reset when not carrying usage forward).
                $periodEnd = EloquentFeatureUsageRepository::nextPeriodEnd($reset, $now, $now);
                $payload = [
                    'feature_id'   => $feature->id,
                    'usage'        => 0,
                    'limit_value'  => $limitValue,
                    'reset_period' => $reset,
                    'period_start' => $now,
                    'period_end'   => $periodEnd,
                ];

                if ($existing) {
                    $existing->update($payload);
                } else {
                    $subscription->featureUsages()->create($payload);
                }
            }
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
        // Churn predicate: a subscription was cancelled by the user.
        // Both immediate (status=Cancelled) and grace (status=PendingCancellation
        // → later Expired) paths set cancelled_at; resume() clears it.
        return Subscription::query()
            ->whereNotNull('cancelled_at')
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

        // Query 1: all cancelled dates in the entire range. Covers both
        // immediate cancels and grace cancels (which transition through
        // PendingCancellation → Expired) — cancelled_at is the canonical
        // signal of user-initiated churn.
        $cancelledDates = Subscription::query()
            ->whereNotNull('cancelled_at')
            ->where('cancelled_at', '>=', $earliestWindow)
            ->pluck('cancelled_at')
            ->map(fn ($d) => Carbon::parse($d));

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
                $created = Carbon::parse($row->created_at);
                if ($created->gt(now())) {
                    return false;
                }
                if ($row->ends_at === null) {
                    return true;
                }

                return Carbon::parse($row->ends_at)->gte($startOfWindow);
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
                    new Equal("{$packagesTable}.billing_period", new Value('month')),
                ),
                new CaseRule(
                    new Divide("{$packagesTable}.price", new Multiply(new Value(12), "{$packagesTable}.billing_interval")),
                    new Equal("{$packagesTable}.billing_period", new Value('year')),
                ),
                new CaseRule(
                    new Divide(new Multiply("{$packagesTable}.price", new Value(4.33)), "{$packagesTable}.billing_interval"),
                    new Equal("{$packagesTable}.billing_period", new Value('week')),
                ),
                new CaseRule(
                    new Divide(new Multiply("{$packagesTable}.price", new Value(30)), "{$packagesTable}.billing_interval"),
                    new Equal("{$packagesTable}.billing_period", new Value('day')),
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

        // Conversion is measured by the durable trial_converted_at stamp, not
        // current status — a trial that converted and later cancelled/expired
        // still counts as converted.
        $converted = Subscription::query()
            ->whereNotNull('trial_converted_at')
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
                new Alias(new Count('*'), 'count'),
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
                new Alias(new Count('*'), 'count'),
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
                new Alias(new Sum("{$invoicesTable}.amount"), 'revenue'),
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

        $active = new Value(SubscriptionStatus::Active->value);
        $onTrial = new Value(SubscriptionStatus::OnTrial->value);
        $expired = new Value(SubscriptionStatus::Expired->value);

        // Filter: status is active OR on_trial
        $activeFilter = new CondOr(
            new Equal("{$subscriptionsTable}.status", $active),
            new Equal("{$subscriptionsTable}.status", $onTrial),
        );

        // Churn filter: any subscription the user cancelled (immediate or
        // grace). cancelled_at is set by both cancel paths and cleared by
        // resume(), so it's the canonical churn signal.
        $cancelledFilter = new NotIsNull("{$subscriptionsTable}.cancelled_at");

        // MRR calculation per billing period — fully cross-DB
        $mrrCase = new CaseGroup([
            new CaseRule(
                new Divide("{$packagesTable}.price", "{$packagesTable}.billing_interval"),
                new Equal("{$packagesTable}.billing_period", new Value('month')),
            ),
            new CaseRule(
                new Divide("{$packagesTable}.price", new Multiply(new Value(12), "{$packagesTable}.billing_interval")),
                new Equal("{$packagesTable}.billing_period", new Value('year')),
            ),
            new CaseRule(
                new Divide(new Multiply("{$packagesTable}.price", new Value(4.33)), "{$packagesTable}.billing_interval"),
                new Equal("{$packagesTable}.billing_period", new Value('week')),
            ),
            new CaseRule(
                new Divide(new Multiply("{$packagesTable}.price", new Value(30)), "{$packagesTable}.billing_interval"),
                new Equal("{$packagesTable}.billing_period", new Value('day')),
            ),
            new CaseRule(new Value(0), new Equal("{$packagesTable}.billing_period", new Value('lifetime'))),
        ], new Value(0));

        $row = Subscription::query()
            ->leftJoin($packagesTable, "{$subscriptionsTable}.package_id", '=', "{$packagesTable}.id")
            ->select([
                new Alias(new Count('*'), 'total'),
                new Alias(new CountFilter($activeFilter), 'active'),
                new Alias(new CountFilter(new Equal("{$subscriptionsTable}.status", $onTrial)), 'on_trial'),
                new Alias(new CountFilter($cancelledFilter), 'cancelled'),
                new Alias(new CountFilter(new Equal("{$subscriptionsTable}.status", $expired)), 'expired'),
                new Alias(new CountFilter(new NotIsNull("{$subscriptionsTable}.trial_ends_at")), 'total_trials'),
                new Alias(
                    new CountFilter(new NotIsNull("{$subscriptionsTable}.trial_converted_at")),
                    'converted_trials',
                ),
                new Alias(new Coalesce([new SumFilter($mrrCase, $activeFilter), new Value(0)]), 'mrr'),
            ])
            ->first();

        $totalTrials = (int) $row->total_trials;
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

        $active = new Value(SubscriptionStatus::Active->value);
        $onTrial = new Value(SubscriptionStatus::OnTrial->value);

        // Filter: status is active OR on_trial
        $activeFilter = new CondOr(
            new Equal("{$subscriptionsTable}.status", $active),
            new Equal("{$subscriptionsTable}.status", $onTrial),
        );

        // Churn filter: matches both immediate and grace cancellations.
        $cancelledFilter = new NotIsNull("{$subscriptionsTable}.cancelled_at");

        // MRR calculation per billing period
        $mrrCase = new CaseGroup([
            new CaseRule(
                new Divide("{$packagesTable}.price", "{$packagesTable}.billing_interval"),
                new Equal("{$packagesTable}.billing_period", new Value('month')),
            ),
            new CaseRule(
                new Divide("{$packagesTable}.price", new Multiply(new Value(12), "{$packagesTable}.billing_interval")),
                new Equal("{$packagesTable}.billing_period", new Value('year')),
            ),
            new CaseRule(
                new Divide(new Multiply("{$packagesTable}.price", new Value(4.33)), "{$packagesTable}.billing_interval"),
                new Equal("{$packagesTable}.billing_period", new Value('week')),
            ),
            new CaseRule(
                new Divide(new Multiply("{$packagesTable}.price", new Value(30)), "{$packagesTable}.billing_interval"),
                new Equal("{$packagesTable}.billing_period", new Value('day')),
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
                new Alias(new CountFilter($cancelledFilter), 'cancelled_count'),
                new Alias(new CountFilter(new NotIsNull("{$subscriptionsTable}.trial_ends_at")), 'total_trials'),
                new Alias(
                    new CountFilter(new NotIsNull("{$subscriptionsTable}.trial_converted_at")),
                    'converted_trials',
                ),
                new Alias(new Coalesce([new SumFilter($mrrCase, $activeFilter), new Value(0)]), 'mrr'),
                new Alias(
                    new Coalesce([new SumFilter("{$invoicesTable}.amount", new Equal("{$invoicesTable}.status", $paid)), new Value(0)]),
                    'total_revenue',
                ),
            ])
            ->groupBy("{$packagesTable}.id", "{$packagesTable}.name")
            ->get();

        return $rows->map(function ($row) {
            $active = (int) $row->active_subscribers;
            $mrr = round((float) $row->mrr, 2);

            $totalTrials = (int) $row->total_trials;
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
