<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Models;

use Foysal50x\Tashil\Database\Factories\InvoiceFactory;
use Foysal50x\Tashil\Enums\InvoiceKind;
use Foysal50x\Tashil\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected static function newFactory()
    {
        return InvoiceFactory::new();
    }

    protected $guarded = [];

    protected $casts = [
        'status'          => InvoiceStatus::class,
        'kind'            => InvoiceKind::class,
        'amount'          => 'decimal:2',
        'attempts'        => 'integer',
        'issued_at'       => 'datetime',
        'due_date'        => 'datetime',
        'paid_at'         => 'datetime',
        'last_attempt_at' => 'datetime',
        'metadata'        => 'array',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Paid);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Pending);
    }

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::Paid;
    }

    public function isInitial(): bool
    {
        return $this->kind === InvoiceKind::Initial;
    }

    public function isRenewal(): bool
    {
        return $this->kind === InvoiceKind::Renewal;
    }

    public function isProration(): bool
    {
        return $this->kind === InvoiceKind::Proration;
    }

    /**
     * Pending and past its due date — the trigger for the dunning cycle.
     */
    public function isOverdue(): bool
    {
        return $this->status === InvoiceStatus::Pending
            && $this->due_date !== null
            && $this->due_date->isPast();
    }

    public function markAsPaid(): self
    {
        $this->update([
            'status'  => InvoiceStatus::Paid,
            'paid_at' => now(),
        ]);

        return $this;
    }

    public function markAsVoid(): self
    {
        $this->update([
            'status' => InvoiceStatus::Void,
        ]);

        return $this;
    }
}
