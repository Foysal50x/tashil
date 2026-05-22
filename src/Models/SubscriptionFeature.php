<?php

namespace Foysal50x\Tashil\Models;

use Foysal50x\Tashil\Enums\FeatureType;
use Foysal50x\Tashil\Enums\ResetPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Immutable snapshot of a feature on a subscription at a point in time.
 *
 * Once `added_at` is set, the columns describing the snapshot
 * (feature_type, value, reset_period) are frozen. Only `superseded_at`
 * may transition once, from null to a timestamp, when a plan change
 * obsoletes this row.
 */
class SubscriptionFeature extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'feature_type'  => FeatureType::class,
        'reset_period'  => ResetPeriod::class,
        'added_at'      => 'datetime',
        'superseded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            $allowed = ['superseded_at', 'updated_at'];
            foreach ($model->getDirty() as $column => $_value) {
                if (! in_array($column, $allowed, true)) {
                    throw new RuntimeException(
                        "SubscriptionFeature snapshot is immutable; cannot change '{$column}'.",
                    );
                }
            }
        });

        static::deleting(function (self $model): void {
            throw new RuntimeException('SubscriptionFeature snapshots cannot be deleted.');
        });
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNull('superseded_at');
    }

    public function scopeAsOf(Builder $query, \DateTimeInterface $moment): Builder
    {
        return $query->where('added_at', '<=', $moment)
            ->where(function (Builder $q) use ($moment) {
                $q->whereNull('superseded_at')->orWhere('superseded_at', '>', $moment);
            });
    }
}
