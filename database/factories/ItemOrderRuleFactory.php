<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ItemOrderRule>
 */
class ItemOrderRuleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'shop_id' => Shop::factory(),
            'min' => fake()->randomFloat(3, 1, 10),
            'max' => fake()->randomFloat(3, 11, 50),
        ];
    }
}
