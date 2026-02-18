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
        return [
            'subscriber_type' => 'App\\Models\\User',
            'subscriber_id'   => $this->faker->randomNumber(),
            'package_id'      => Package::factory(),
            'status'          => SubscriptionStatus::Active,
            'starts_at'       => now(),
            'ends_at'         => now()->addMonth(),
            'trial_ends_at'   => null,
            'auto_renew'      => true,
        ];
    }
}
