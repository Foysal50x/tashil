<?php

namespace Foysal50x\Tashil\Models;

use Foysal50x\Tashil\Enums\ResetPeriod;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mutable counter for a (subscription, feature) pair. Every mutation is
 * additionally written to UsageLog so the history is reconstructable.
 */
class FeatureUsage extends BaseModel
{
    protected $guarded = [];

    protected $casts = [
        'usage'        => 'float',
        'limit_value'  => 'float',
        'reset_period' => ResetPeriod::class,
        'period_start' => 'datetime',
        'period_end'   => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function isUnlimited(): bool
    {
        return $this->limit_value === null;
    }

    public function remaining(): ?float
    {
        if ($this->isUnlimited()) {
            return null;
        }

        return max(0.0, (float) $this->limit_value - (float) $this->usage);
    }

    public function overLimit(float $additional = 0): bool
    {
        if ($this->isUnlimited()) {
            return false;
        }

        return ((float) $this->usage + $additional) > (float) $this->limit_value;
    }

    public function usagePercentage(): ?float
    {
        if ($this->isUnlimited() || (float) $this->limit_value === 0.0) {
            return null;
        }

        return round(((float) $this->usage / (float) $this->limit_value) * 100, 2);
    }
}
