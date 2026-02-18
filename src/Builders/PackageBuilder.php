<?php

namespace Foysal50x\Tashil\Builders;

use Foysal50x\Tashil\Contracts\PackageRepositoryInterface;
use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Models\Feature;
use Foysal50x\Tashil\Models\Package;
use Illuminate\Support\Facades\Config;

class PackageBuilder
{
    protected string $slug;
    protected string $name;
    protected ?string $description = null;
    protected float $price = 0.00;
    protected ?float $originalPrice = null;
    protected string $currency;
    protected Period $billingPeriod = Period::Month;
    protected int $billingInterval = 1;
    protected int $trialDays = 0;
    protected bool $isActive = true;
    protected bool $isFeatured = false;
    protected int $sortOrder = 0;
    protected ?array $metadata = null;

    /**
     * Features to attach after package creation.
     * Each entry: ['feature' => Feature|int, 'value' => ?string, 'is_available' => bool, 'sort_order' => int]
     *
     * @var array<int, array{feature: Feature|int, value: ?string, is_available: bool, sort_order: int}>
     */
    protected array $featureAttachments = [];

    public function __construct(string $slug)
    {
        $this->slug = $slug;
        $this->name = $slug;
        $this->currency = Config::get('tashil.currency', 'USD');
    }

    // ── Fluent setters ──────────────────────────────────────────

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function description(string $description): self
    {
        $this->description = $description;

        return $this;
    }

    public function price(float $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function originalPrice(float $originalPrice): self
    {
        $this->originalPrice = $originalPrice;

        return $this;
    }

    public function currency(string $currency): self
    {
        $this->currency = $currency;

        return $this;
    }

    public function billingPeriod(Period $period, int $interval = 1): self
    {
        $this->billingPeriod = $period;
        $this->billingInterval = $interval;

        return $this;
    }

    /**
     * Shorthands for common billing cycles.
     */
    public function monthly(): self
    {
        return $this->billingPeriod(Period::Month, 1);
    }

    public function quarterly(): self
    {
        return $this->billingPeriod(Period::Month, 3);
    }

    public function yearly(): self
    {
        return $this->billingPeriod(Period::Year, 1);
    }

    public function lifetime(): self
    {
        return $this->billingPeriod(Period::Lifetime, 1);
    }

    public function trialDays(int $days): self
    {
        $this->trialDays = $days;

        return $this;
    }

    public function active(bool $active = true): self
    {
        $this->isActive = $active;

        return $this;
    }

    public function featured(bool $featured = true): self
    {
        $this->isFeatured = $featured;

        return $this;
    }

    public function sortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function metadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    // ── Feature assignment ──────────────────────────────────────

    /**
     * Attach a single feature to this package.
     *
     * @param  Feature|int  $feature  Feature model or ID
     * @param  string|null  $value    Default value for this feature in this package
     * @param  bool         $isAvailable
     * @param  int          $sortOrder
     */
    public function feature(
        Feature|int $feature,
        ?string $value = null,
        bool $isAvailable = true,
        int $sortOrder = 0
    ): self {
        $this->featureAttachments[] = [
            'feature'      => $feature,
            'value'        => $value,
            'is_available' => $isAvailable,
            'sort_order'   => $sortOrder,
        ];

        return $this;
    }

    /**
     * Attach multiple features at once (with optional per-feature values).
     *
     * Usage:
     *   ->features([$feature1, $feature2])
     *   ->features([$feature1 => ['value' => '100'], $feature2 => ['value' => 'true']])
     *
     * @param  array<int, Feature|int>|array<Feature|int, array{value?: string, is_available?: bool, sort_order?: int}>  $features
     */
    public function features(array $features): self
    {
        foreach ($features as $key => $item) {
            if ($item instanceof Feature || is_int($item)) {
                // Simple list: ->features([$f1, $f2])
                $this->feature($item);
            } elseif (is_array($item)) {
                // Associative: key is Feature|id, value is config array
                $this->feature(
                    $key,
                    $item['value'] ?? null,
                    $item['is_available'] ?? true,
                    $item['sort_order'] ?? 0,
                );
            }
        }

        return $this;
    }

    // ── Terminal operations ──────────────────────────────────────

    /**
     * Create the package and attach features.
     */
    public function create(): Package
    {
        $package = $this->repo()->create($this->toArray());

        $this->attachFeatures($package);

        return $package;
    }

    /**
     * Create or update the package by slug, then sync features.
     */
    public function createOrUpdate(): Package
    {
        $package = $this->repo()->updateOrCreate(
            ['slug' => $this->slug],
            $this->toArray()
        );

        $this->attachFeatures($package);

        return $package;
    }

    /**
     * Get the package attributes array without persisting.
     */
    public function toArray(): array
    {
        return [
            'slug'             => $this->slug,
            'name'             => $this->name,
            'description'      => $this->description,
            'price'            => $this->price,
            'original_price'   => $this->originalPrice,
            'currency'         => $this->currency,
            'billing_period'   => $this->billingPeriod,
            'billing_interval' => $this->billingInterval,
            'trial_days'       => $this->trialDays,
            'is_active'        => $this->isActive,
            'is_featured'      => $this->isFeatured,
            'sort_order'       => $this->sortOrder,
            'metadata'         => $this->metadata,
        ];
    }

    // ── Internal ────────────────────────────────────────────────

    protected function attachFeatures(Package $package): void
    {
        if (empty($this->featureAttachments)) {
            return;
        }

        $syncData = [];

        foreach ($this->featureAttachments as $attachment) {
            $featureId = $attachment['feature'] instanceof Feature
                ? $attachment['feature']->id
                : $attachment['feature'];

            $syncData[$featureId] = [
                'value'        => $attachment['value'],
                'is_available' => $attachment['is_available'],
                'sort_order'   => $attachment['sort_order'],
            ];
        }

        $this->repo()->syncFeatures($package, $syncData);
    }

    protected function repo(): PackageRepositoryInterface
    {
        return app(PackageRepositoryInterface::class);
    }
}
