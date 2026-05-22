<?php

namespace Foysal50x\Tashil\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class SubscriptionEvent extends BaseModel
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'payload'     => 'array',
        'metadata'    => 'array',
        'occurred_at' => 'datetime',
        'recorded_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            throw new RuntimeException('SubscriptionEvent rows are append-only and cannot be updated.');
        });

        static::deleting(function (self $model): void {
            throw new RuntimeException('SubscriptionEvent rows are append-only and cannot be deleted.');
        });
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('event_type', $type);
    }

    public function scopeUpTo(Builder $query, \DateTimeInterface $moment): Builder
    {
        return $query->where('occurred_at', '<=', $moment);
    }
}
