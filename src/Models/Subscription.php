<?php

namespace Foysal50x\Tashil\Models;

use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Subscription extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected static function newFactory()
    {
        return \Foysal50x\Tashil\Database\Factories\SubscriptionFactory::new();
    }

    protected $guarded = [];

    protected $casts = [
        'status'              => SubscriptionStatus::class,
        'starts_at'           => 'datetime',
        'ends_at'             => 'datetime',
        'trial_ends_at'       => 'datetime',
        'cancelled_at'        => 'datetime',
        'auto_renew'          => 'boolean',
        'metadata'            => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────

    public function subscriber(): MorphTo
    {
        return $this->morphTo();
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(UsageLog::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    // ── Status helpers ───────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active
            && (! $this->ends_at || $this->ends_at->isFuture());
    }

    public function isOnTrial(): bool
    {
        return $this->status === SubscriptionStatus::OnTrial
            || ($this->trial_ends_at && $this->trial_ends_at->isFuture());
    }

    public function isCancelled(): bool
    {
        return $this->status === SubscriptionStatus::Cancelled
            || ! is_null($this->cancelled_at);
    }

    public function isExpired(): bool
    {
        return $this->status === SubscriptionStatus::Expired
            || ($this->ends_at && $this->ends_at->isPast() && ! $this->isCancelled());
    }

    public function isSuspended(): bool
    {
        return $this->status === SubscriptionStatus::Suspended;
    }

    public function isPastDue(): bool
    {
        return $this->status === SubscriptionStatus::PastDue;
    }

    public function isValid(): bool
    {
        return $this->isActive() || $this->isOnTrial();
    }

    // ── Scopes ───────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Active);
    }

    public function scopeOnTrial(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::OnTrial);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Expired);
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Cancelled);
    }

    /**
     * Scope to subscriptions that are currently valid (active or on trial).
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->whereIn('status', [
            SubscriptionStatus::Active,
            SubscriptionStatus::OnTrial,
        ]);
    }
}
