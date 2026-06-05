<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Models;

use Foysal50x\Tashil\Enums\TransactionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends BaseModel
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'status'          => TransactionStatus::class,
        'amount'          => 'decimal:2',
        'refunded_amount' => 'decimal:2',
        'refunded_at'     => 'datetime',
        'metadata'        => 'array',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function isSuccessful(): bool
    {
        return $this->status === TransactionStatus::Success;
    }

    public function isRefunded(): bool
    {
        return $this->status === TransactionStatus::Refunded;
    }
}
