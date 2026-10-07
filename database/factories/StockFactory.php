<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Stock>
 */
class StockFactory extends Factory
{
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'shop_id' => Shop::factory(),
            'qty' => fake()->randomFloat(3, 0, 100),
            'stock_date' => now()->toDateString(),
        ];
    }
}
