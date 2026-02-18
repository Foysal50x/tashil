<?php

namespace Foysal50x\Tashil\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionItem extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'usage'      => 'integer',
        'sort_order' => 'integer',
    ];

    // ── Relationships ────────────────────────────────────────────

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    // ── Helpers ──────────────────────────────────────────────────

    /**
     * Check if usage has reached or exceeded the configured value (limit).
     */
    public function overLimit(float $additionalAmount = 0): bool
    {
        if (is_null($this->value)) {
            return false; // unlimited
        }

        return ($this->usage + $additionalAmount) > (float) $this->value;
    }

    /**
     * Remaining usage before hitting the limit.
     */
    public function remaining(): ?float
    {
        if (is_null($this->value)) {
            return null; // unlimited
        }

        return max(0, (float) $this->value - $this->usage);
    }

    /**
     * Usage percentage (0–100).
     */
    public function usagePercentage(): ?float
    {
        if (is_null($this->value) || (float) $this->value === 0.0) {
            return null;
        }

        return round(($this->usage / (float) $this->value) * 100, 2);
    }
}
