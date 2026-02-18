<?php

namespace Foysal50x\Tashil\Models;

use Foysal50x\Tashil\Enums\FeatureType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Config;

class Feature extends BaseModel
{
    use HasFactory, SoftDeletes;

    protected $guarded = [];

    protected $casts = [
        'type'       => FeatureType::class,
        'is_active'  => 'boolean',
        'sort_order' => 'integer',
        'metadata'   => 'array',
    ];

    // ── Relationships ────────────────────────────────────────────

    public function packages(): BelongsToMany
    {
        return $this->belongsToMany(
            Package::class,
            Config::get('tashil.database.prefix', 'tashil_') . Config::get('tashil.database.tables.package_feature', 'package_feature'),
            'feature_id',
            'package_id'
        )->withPivot(['value', 'is_available', 'sort_order'])->withTimestamps();
    }

    // ── Scopes ───────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order');
    }

    // ── Helpers ──────────────────────────────────────────────────

    public function isBoolean(): bool
    {
        return $this->type === FeatureType::Boolean;
    }

    public function isLimit(): bool
    {
        return $this->type === FeatureType::Limit;
    }

    public function isConsumable(): bool
    {
        return $this->type === FeatureType::Consumable;
    }
}
