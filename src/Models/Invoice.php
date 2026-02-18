<?php

namespace Foysal50x\Tashil\Models;

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
        return \Foysal50x\Tashil\Database\Factories\InvoiceFactory::new();
    }

    protected $guarded = [];

    protected $casts = [
        'status'    => InvoiceStatus::class,
        'amount'    => 'decimal:2',
        'issued_at' => 'datetime',
        'due_date'  => 'datetime',
        'paid_at'   => 'datetime',
        'metadata'  => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    // ── Scopes ───────────────────────────────────────────────────

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Paid);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', InvoiceStatus::Pending);
    }

    // ── Helpers ──────────────────────────────────────────────────

    public function isPaid(): bool
    {
        return $this->status === InvoiceStatus::Paid;
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
