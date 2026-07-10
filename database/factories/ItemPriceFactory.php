<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Price;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ItemPrice>
 */
class ItemPriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'price_id' => Price::factory(),
            'value' => fake()->randomFloat(2, 1, 1000),
        ];
    }
}
