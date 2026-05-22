<?php

namespace Foysal50x\Tashil\Database\Factories;

use Foysal50x\Tashil\Enums\SubscriptionStatus;
use Foysal50x\Tashil\Models\Package;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        $start = now();

        return [
            'subscriber_type'      => 'App\\Models\\User',
            'subscriber_id'        => $this->faker->randomNumber(),
            'package_id'           => Package::factory(),
            'status'               => SubscriptionStatus::Active,
            'starts_at'            => $start,
            'ends_at'              => $start->copy()->addMonth(),
            'current_period_start' => $start,
            'current_period_end'   => $start->copy()->addMonth(),
            'trial_ends_at'        => null,
            'auto_renew'           => true,
        ];
    }
}
