<?php

namespace Foysal50x\Tashil\Models;

use Foysal50x\Tashil\Enums\Period;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;

class Package extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected static function newFactory()
    {
        return \Foysal50x\Tashil\Database\Factories\PackageFactory::new();
    }

    protected $guarded = [];

    protected $casts = [
        'billing_period'  => Period::class,
        'price'           => 'decimal:2',
        'original_price'  => 'decimal:2',
        'billing_interval' => 'integer',
        'trial_days'      => 'integer',
        'is_active'       => 'boolean',
        'is_featured'     => 'boolean',
        'sort_order'      => 'integer',
        'metadata'        => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(
            Feature::class,
            Config::get('tashil.database.prefix', 'tashil_') . Config::get('tashil.database.tables.package_feature', 'package_feature'),
            'package_id',
            'feature_id'
        )->withPivot(['value', 'is_available', 'sort_order'])->withTimestamps();
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    // ── Scopes ───────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }
}
