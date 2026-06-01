<?php

namespace Foysal50x\Tashil\Repositories;

use Carbon\Carbon;
use Foysal50x\Tashil\Contracts\FeatureUsageRepositoryInterface;
use Foysal50x\Tashil\Enums\ResetPeriod;
use Foysal50x\Tashil\Models\FeatureUsage;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Database\Eloquent\Collection;

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
        $table = $usage->getTable();
        $connection = $usage->getConnection();

        // Fully parameterized: no DB::raw with string-interpolated values.
        // Single round-trip conditional UPDATE: usage advances iff the
        // counter is unlimited or the new total fits the limit.
        $affected = $connection->update(
            "UPDATE {$table} SET usage = usage + ?, updated_at = ? "
            . 'WHERE id = ? AND (limit_value IS NULL OR usage + ? <= limit_value)',
            [$amount, now(), $usage->id, $amount],
        );

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
        return $this->dueForResetQuery($now)->get();
    }

    public function chunkDueForReset(\DateTimeInterface $now, int $chunkSize, callable $callback): void
    {
        $this->dueForResetQuery($now)->chunkById(max(1, $chunkSize), function ($rows) use ($callback) {
            $callback($rows);
        });
    }

    protected function dueForResetQuery(\DateTimeInterface $now)
    {
        return FeatureUsage::query()
            ->where('reset_period', '!=', ResetPeriod::Never->value)
            ->whereNotNull('period_end')
            ->where('period_end', '<=', $now)
            ->orderBy('id');
    }

    public function reportAbsolute(FeatureUsage $usage, float $amount): float
    {
        $usage->update(['usage' => $amount]);

        return (float) $usage->usage;
    }

    /**
     * Cap on how many periods we'll advance in one nextPeriodEnd call.
     * Under the expected cron cadence this loop runs once or twice; any
     * value past this points to bad input ($now far in the future, or a
     * subscription that hasn't been touched in years) and we'd rather
     * fail loudly than burn CPU iterating tens of thousands of times.
     */
    public const MAX_PERIOD_ADVANCE_ITERATIONS = 5000;

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
        $iterations = 0;

        do {
            $cursor = match ($period) {
                ResetPeriod::Daily   => $cursor->copy()->addDay(),
                ResetPeriod::Weekly  => $cursor->copy()->addWeek(),
                ResetPeriod::Monthly => $cursor->copy()->addMonth(),
                ResetPeriod::Yearly  => $cursor->copy()->addYear(),
                default              => throw new \LogicException('Unhandled reset period'),
            };

            if (++$iterations >= self::MAX_PERIOD_ADVANCE_ITERATIONS) {
                throw new \RuntimeException(sprintf(
                    'nextPeriodEnd exceeded %d iterations advancing %s from %s past %s — check that period_end is not pathologically stale.',
                    self::MAX_PERIOD_ADVANCE_ITERATIONS,
                    $period->value,
                    $anchor->toIso8601String(),
                    Carbon::instance($now)->toIso8601String(),
                ));
            }
        } while ($cursor->lessThanOrEqualTo($now));

        return $cursor;
    }
}
