<?php

namespace Database\Factories;

use App\Models\Pos;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Receipt>
 */
class ReceiptFactory extends Factory
{
    public function definition(): array
    {
        return [
            'pos_id' => Pos::factory(),
            'shop_id' => Shop::factory(),
            'number' => fake()->unique()->numerify('RCPT-######'),
            'client' => fake()->optional()->name(),
            'cashier' => fake()->name(),
            'total' => fake()->randomFloat(2, 10, 500),
            'discount' => 0,
            'active' => true,
            'sell' => true,
        ];
    }
}
