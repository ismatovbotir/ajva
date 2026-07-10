<?php

namespace Database\Factories;

use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Barcode>
 */
class BarcodeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'item_id' => Item::factory(),
            'gtin' => fake()->unique()->ean13(),
        ];
    }
}
