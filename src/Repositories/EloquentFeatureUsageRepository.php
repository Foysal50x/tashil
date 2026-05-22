<?php

namespace Foysal50x\Tashil\Repositories;

use Carbon\Carbon;
use Foysal50x\Tashil\Contracts\FeatureUsageRepositoryInterface;
use Foysal50x\Tashil\Enums\ResetPeriod;
use Foysal50x\Tashil\Models\FeatureUsage;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class EloquentFeatureUsageRepository implements FeatureUsageRepositoryInterface
{
    public function findBySlug(Subscription $subscription, string $featureSlug, bool $withFeature = false): ?FeatureUsage
    {
        $query = FeatureUsage::query()
            ->where('subscription_id', $subscription->id)
            ->whereHas('feature', fn ($q) => $q->where('slug', $featureSlug));

        if ($withFeature) {
            $query->with('feature');
        }

        return $query->first();
    }

    public function insert(array $data): FeatureUsage
    {
        return FeatureUsage::query()->create($data);
    }

    public function atomicIncrement(FeatureUsage $usage, float $amount): ?float
    {
        $affected = FeatureUsage::query()
            ->where('id', $usage->id)
            ->where(function ($q) use ($amount) {
                $q->whereNull('limit_value')
                    ->orWhereRaw('usage + ? <= limit_value', [$amount]);
            })
            ->update(['usage' => DB::raw('usage + ' . (float) $amount)]);

        if ($affected === 0) {
            return null;
        }

        $usage->refresh();

        return (float) $usage->usage;
    }

    public function resetUsage(FeatureUsage $usage): void
    {
        $now = now();
        $next = self::nextPeriodEnd($usage->reset_period, $usage->period_end ?: $now, $now);

        $usage->update([
            'usage'        => 0,
            'period_start' => $usage->period_end ?: $now,
            'period_end'   => $next,
        ]);
    }

    public function resetAllUsage(Subscription $subscription): void
    {
        FeatureUsage::query()
            ->where('subscription_id', $subscription->id)
            ->update(['usage' => 0]);
    }

    public function dueForReset(\DateTimeInterface $now): Collection
    {
        return FeatureUsage::query()
            ->where('reset_period', '!=', ResetPeriod::Never->value)
            ->whereNotNull('period_end')
            ->where('period_end', '<=', $now)
            ->orderBy('id')
            ->get();
    }

    public function reportAbsolute(FeatureUsage $usage, float $amount): float
    {
        $usage->update(['usage' => $amount]);

        return (float) $usage->usage;
    }

    /**
     * Compute the next period_end anchored to the previous period_end
     * (not now()) so that a delayed cron does not drift the schedule.
     */
    public static function nextPeriodEnd(?ResetPeriod $period, \DateTimeInterface $previousEnd, \DateTimeInterface $now): ?\DateTimeInterface
    {
        if ($period === null || $period === ResetPeriod::Never) {
            return null;
        }

        $anchor = Carbon::instance($previousEnd);
        $cursor = $anchor->copy();

        do {
            $cursor = match ($period) {
                ResetPeriod::Daily   => $cursor->copy()->addDay(),
                ResetPeriod::Weekly  => $cursor->copy()->addWeek(),
                ResetPeriod::Monthly => $cursor->copy()->addMonth(),
                ResetPeriod::Yearly  => $cursor->copy()->addYear(),
                default              => throw new \LogicException('Unhandled reset period'),
            };
        } while ($cursor->lessThanOrEqualTo($now));

        return $cursor;
    }
}
