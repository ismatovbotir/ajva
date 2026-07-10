<?php

namespace Database\Factories;

use App\Models\Receipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ReceiptPayment>
 */
class ReceiptPaymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'receipt_id' => Receipt::factory(),
            'payment' => fake()->randomElement(['cash', 'card']),
            'value' => fake()->randomFloat(2, 10, 500),
        ];
    }
}
