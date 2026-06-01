<?php

namespace Foysal50x\Tashil\Builders;

use Foysal50x\Tashil\Contracts\FeatureRepositoryInterface;
use Foysal50x\Tashil\Enums\FeatureType;
use Foysal50x\Tashil\Enums\ResetPeriod;
use Foysal50x\Tashil\Models\Feature;

class FeatureBuilder
{
    protected string $slug;

    protected string $name;

    protected ?string $description = null;

    protected FeatureType $type = FeatureType::Boolean;

    protected ResetPeriod $resetPeriod = ResetPeriod::Never;

    protected bool $isActive = true;

    protected int $sortOrder = 0;

    protected ?array $metadata = null;

    public function __construct(string $slug)
    {
        $this->slug = $slug;
        $this->name = $slug; // sensible default
    }

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

    public function type(FeatureType $type): self
    {
        $this->type = $type;

        return $this;
    }

    /**
     * Shorthand for ->type(FeatureType::Boolean)
     */
    public function boolean(): self
    {
        return $this->type(FeatureType::Boolean);
    }

    /**
     * Shorthand for ->type(FeatureType::Limit)
     */
    public function limit(): self
    {
        return $this->type(FeatureType::Limit);
    }

    /**
     * Shorthand for ->type(FeatureType::Consumable)
     */
    public function consumable(): self
    {
        return $this->type(FeatureType::Consumable);
    }

    /**
     * Shorthand for ->type(FeatureType::Metered)
     *
     * Metered features charge `units × unit_price` against the subscriber's
     * balance on every consume. The unit_price is stored on the package
     * pivot value (decimal string) and snapshotted at subscribe time.
     * Requires a bound MeteredBilling in the host application.
     */
    public function metered(): self
    {
        return $this->type(FeatureType::Metered);
    }

    /**
     * Set the reset cadence for a counter-style feature (Limit /
     * Consumable). The scheduled `tashil:reset-quotas` job zeroes
     * matching feature_usages rows when period_end elapses.
     *
     * Boolean / Enum / Metered features ignore reset_period — Boolean
     * and Enum are stateless gates, Metered consumes per-call with no
     * counter quota.
     */
    public function resetPeriod(ResetPeriod $period): self
    {
        $this->resetPeriod = $period;

        return $this;
    }

    public function resetDaily(): self
    {
        return $this->resetPeriod(ResetPeriod::Daily);
    }

    public function resetWeekly(): self
    {
        return $this->resetPeriod(ResetPeriod::Weekly);
    }

    public function resetMonthly(): self
    {
        return $this->resetPeriod(ResetPeriod::Monthly);
    }

    public function resetYearly(): self
    {
        return $this->resetPeriod(ResetPeriod::Yearly);
    }

    public function active(bool $active = true): self
    {
        $this->isActive = $active;

        return $this;
    }

    public function inactive(): self
    {
        return $this->active(false);
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

    /**
     * Create the feature in the database.
     */
    public function create(): Feature
    {
        return $this->repo()->create($this->toArray());
    }

    /**
     * Create or update the feature by slug.
     */
    public function createOrUpdate(): Feature
    {
        return $this->repo()->updateOrCreate(
            ['slug' => $this->slug],
            $this->toArray(),
        );
    }

    /**
     * Get the attributes array without persisting.
     */
    public function toArray(): array
    {
        return [
            'slug'         => $this->slug,
            'name'         => $this->name,
            'description'  => $this->description,
            'type'         => $this->type,
            'reset_period' => $this->resetPeriod,
            'is_active'    => $this->isActive,
            'sort_order'   => $this->sortOrder,
            'metadata'     => $this->metadata,
        ];
    }

    protected function repo(): FeatureRepositoryInterface
    {
        return app(FeatureRepositoryInterface::class);
    }
}
