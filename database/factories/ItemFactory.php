<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Item>
 */
class ItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'group_id' => null,
            'category_id' => null,
            'name' => fake()->unique()->words(3, true),
            'mark' => fake()->unique()->bothify('MRK-####'),
            'class_code' => null,
            'package_code' => null,
        ];
    }
}
