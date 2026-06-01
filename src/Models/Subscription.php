<?php

namespace Foysal50x\Tashil\Models;

use Foysal50x\Tashil\Database\Factories\SubscriptionFactory;
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
        return SubscriptionFactory::new();
    }

    protected $guarded = [];

    protected $casts = [
        'status'                    => SubscriptionStatus::class,
        'starts_at'                 => 'datetime',
        'ends_at'                   => 'datetime',
        'current_period_start'      => 'datetime',
        'current_period_end'        => 'datetime',
        'trial_started_at'          => 'datetime',
        'trial_ends_at'             => 'datetime',
        'trial_converted_at'        => 'datetime',
        'trial_expired_at'          => 'datetime',
        'cancelled_at'              => 'datetime',
        'cancellation_effective_at' => 'datetime',
        'pending_change_at'         => 'datetime',
        'last_event_seq'            => 'integer',
        'auto_renew'                => 'boolean',
        'metadata'                  => 'array',
    ];

    public function subscriber(): MorphTo
    {
        return $this->morphTo();
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(Package::class);
    }

    public function pendingPackage(): BelongsTo
    {
        return $this->belongsTo(Package::class, 'pending_package_id');
    }

    public function subscriptionFeatures(): HasMany
    {
        return $this->hasMany(SubscriptionFeature::class);
    }

    public function currentFeatures(): HasMany
    {
        return $this->subscriptionFeatures()->whereNull('superseded_at');
    }

    public function featureUsages(): HasMany
    {
        return $this->hasMany(FeatureUsage::class);
    }

    public function usageLogs(): HasMany
    {
        return $this->hasMany(UsageLog::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class)->orderBy('sequence_num');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function isActive(): bool
    {
        return $this->status === SubscriptionStatus::Active
            && (! $this->ends_at || $this->ends_at->isFuture());
    }

    public function isOnTrial(): bool
    {
        return $this->status === SubscriptionStatus::OnTrial
            && $this->trial_ends_at !== null
            && $this->trial_ends_at->isFuture();
    }

    public function isCancelled(): bool
    {
        return $this->status === SubscriptionStatus::Cancelled;
    }

    public function isPendingCancellation(): bool
    {
        return $this->status === SubscriptionStatus::PendingCancellation;
    }

    public function isExpired(): bool
    {
        return $this->status === SubscriptionStatus::Expired;
    }

    public function isPaused(): bool
    {
        return $this->status === SubscriptionStatus::Paused;
    }

    public function isSuspended(): bool
    {
        return $this->status === SubscriptionStatus::Suspended;
    }

    public function isPastDue(): bool
    {
        return $this->status === SubscriptionStatus::PastDue;
    }

    /**
     * The subscriber currently has access. Includes pending-cancellation
     * (grace period) so that a user who cancelled mid-month keeps using
     * features until current_period_end / ends_at passes.
     */
    public function isValid(): bool
    {
        return $this->isActive()
            || $this->isOnTrial()
            || ($this->isPendingCancellation() && (! $this->ends_at || $this->ends_at->isFuture()));
    }

    public function hasPendingChange(): bool
    {
        return $this->pending_package_id !== null && $this->pending_change_at !== null;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('status', SubscriptionStatus::Active)
            ->where(function (Builder $q) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }

    public function scopeOnTrial(Builder $query): Builder
    {
        return $query
            ->where('status', SubscriptionStatus::OnTrial)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>', now());
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Expired);
    }

    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Cancelled);
    }

    public function scopePendingCancellation(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::PendingCancellation);
    }

    public function scopePaused(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatus::Paused);
    }

    /**
     * Subscriptions that still grant access — Active, OnTrial, or
     * PendingCancellation while the grace window has not elapsed.
     */
    public function scopeValid(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where(function (Builder $q) {
                $q->where('status', SubscriptionStatus::Active)
                    ->where(function (Builder $q) {
                        $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
                    });
            })->orWhere(function (Builder $q) {
                $q->where('status', SubscriptionStatus::OnTrial)
                    ->whereNotNull('trial_ends_at')
                    ->where('trial_ends_at', '>', now());
            })->orWhere(function (Builder $q) {
                $q->where('status', SubscriptionStatus::PendingCancellation)
                    ->where(function (Builder $q) {
                        $q->whereNull('ends_at')->orWhere('ends_at', '>', now());
                    });
            });
        });
    }
}
