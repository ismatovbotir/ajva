<?php

namespace Database\Factories;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Pos>
 */
class PosFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'POS '.fake()->unique()->numerify('##'),
            'shop_id' => Shop::factory(),
        ];
    }
}
