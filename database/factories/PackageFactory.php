<?php

declare(strict_types=1);

namespace Foysal50x\Tashil\Database\Factories;

use Foysal50x\Tashil\Enums\Period;
use Foysal50x\Tashil\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        $name = $this->faker->words(3, true);

        return [
            'name'             => $name,
            'slug'             => Str::slug($name),
            'description'      => $this->faker->sentence,
            'price'            => $this->faker->randomFloat(2, 10, 1000),
            'currency'         => 'USD',
            'billing_period'   => Period::Month,
            'billing_interval' => 1,
            'trial_days'       => 0,
            // requires_payment is intentionally NOT set here: Package::booted()
            // seeds it from tashil.billing.activate_on_payment at creation, so
            // factory packages inherit the install-wide default like real ones.
            // Override explicitly (->state(['requires_payment' => ...])) to pin it.
            'is_active'  => true,
            'sort_order' => 0,
        ];
    }

    /**
     * Pin requires_payment regardless of the install-wide default.
     */
    public function requiresPayment(bool $requires = true): static
    {
        return $this->state(['requires_payment' => $requires]);
    }
}
