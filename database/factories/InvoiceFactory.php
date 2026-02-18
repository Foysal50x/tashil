<?php

namespace Foysal50x\Tashil\Database\Factories;

use Foysal50x\Tashil\Enums\InvoiceStatus;
use Foysal50x\Tashil\Models\Invoice;
use Foysal50x\Tashil\Models\Subscription;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'amount'          => $this->faker->randomFloat(2, 10, 500),
            'currency'        => 'USD',
            'status'          => InvoiceStatus::Pending,
            'issued_at'       => now(),
            'due_date'        => now()->addDays(7),
        ];
    }
}
