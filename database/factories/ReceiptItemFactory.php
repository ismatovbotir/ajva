<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\Receipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ReceiptItem>
 */
class ReceiptItemFactory extends Factory
{
    public function definition(): array
    {
        $qty = fake()->randomFloat(3, 1, 5);
        $price = fake()->randomFloat(2, 5, 100);

        return [
            'receipt_id' => Receipt::factory(),
            'item_id' => Item::factory(),
            'active' => true,
            'qty' => $qty,
            'price' => $price,
            'discount' => 0,
            'total' => round($qty * $price, 2),
            'receipt_active' => true,
            'receipt_sell' => true,
        ];
    }
}
