<?php

namespace Foysal50x\Tashil\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageLog extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'amount'   => 'decimal:2',
        'metadata' => 'array',
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
}
