<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderEventNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_long_note_is_cut_to_the_column_width(): void
    {
        $order = Order::query()->create(['shop_id' => Shop::factory()->create()->id, 'status' => OrderStatus::Draft, 'origin' => 'ordering_report', 'horizon_days' => 3]);
        $e = OrderEvent::record($order, null, OrderEvent::CHANNEL_SYSTEM, OrderEvent::COMMENT, null, null, null, str_repeat('ж', 1500));

        $this->assertSame(255, mb_strlen($e->fresh()->note));
    }
}
