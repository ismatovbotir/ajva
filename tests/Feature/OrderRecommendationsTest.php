<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\Order;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\Stock;
use App\Services\OrderRecommendations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OrderRecommendationsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $a;

    private Shop $b;

    private Shop $wh;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->a = Shop::factory()->create(['name' => 'Alpha']);
        $this->b = Shop::factory()->create(['name' => 'Beta']);
        $this->wh = Shop::factory()->create(['name' => 'Main warehouse']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** An item with a stock history (first snapshot $firstSeen, current snapshot today) and a rule in $shop. */
    private function item(string $name, Shop $shop, float $stock, float $min, float $max, string $firstSeen = '2026-09-01'): Item
    {
        $item = Item::factory()->create(['name' => $name]);
        Stock::query()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => $stock, 'stock_date' => $firstSeen]);
        if ($firstSeen !== '2026-10-07') {
            Stock::query()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => $stock, 'stock_date' => '2026-10-07']);
        }
        ItemOrderRule::query()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'min' => $min, 'max' => $max]);

        return $item;
    }

    private function warehouseHas(Item $item, float $qty): void
    {
        Stock::query()->create(['shop_id' => $this->wh->id, 'item_id' => $item->id, 'qty' => $qty, 'stock_date' => '2026-10-07']);
    }

    private function sell(Shop $shop, Item $item, float $qty, string $at, bool $sell = true, bool $active = true, bool $storno = false): void
    {
        $receipt = Receipt::factory()->create(['shop_id' => $shop->id, 'active' => $active, 'sell' => $sell, 'created_at' => $at]);
        ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'qty' => $qty, 'storno' => $storno]);
    }

    /** The same quantity sold every day over the $days complete days before today. */
    private function sellDaily(Shop $shop, Item $item, float $qty, int $days = 28): void
    {
        for ($i = 1; $i <= $days; $i++) {
            $this->sell($shop, $item, $qty, Carbon::now()->subDays($i)->setTime(11, 0)->toDateTimeString());
        }
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(array $rec, Shop $shop): array
    {
        return collect(collect($rec['shops'])->firstWhere('id', $shop->id)['rows'])->keyBy('item')->all();
    }

    private function build(?array $ids = null, bool $warehouse = true, ?int $horizon = null): array
    {
        return app(OrderRecommendations::class)->build($ids, $warehouse ? $this->wh->id : null, null, $horizon);
    }

    public function test_speed_and_cover_recommend_an_item_that_is_above_min(): void
    {
        $fine = $this->item('Fine', $this->a, 30, 5, 20);      // 2/day, cover 15 days: not listed
        $this->sellDaily($this->a, $fine, 2);
        $soon = $this->item('Soon', $this->a, 5, 2, 10);       // 2/day, cover 2.5 days, above min 2
        $this->sellDaily($this->a, $soon, 2);

        $rows = $this->rows($this->build(), $this->a);

        $this->assertArrayNotHasKey('Fine', $rows);
        $row = $rows['Soon'];
        $this->assertEqualsWithDelta(2.0, $row['speed'], 0.001);
        $this->assertEqualsWithDelta(2.5, $row['cover'], 0.001);
        $this->assertSame('cover', $row['reason']);
        $this->assertSame('soon', $row['urgency']);
        $this->assertSame('high', $row['confidence']);
        // horizon 3: F = rest of today 2 + 3 x 2 = 8; order = ceil(min 2 + 8 - 5)
        $this->assertEquals(5, $row['need']);
    }

    public function test_urgency_follows_the_cover_and_the_order_grows_with_the_horizon(): void
    {
        $hot = $this->item('Hot', $this->a, 4, 1, 10);         // 10/day -> cover 0.4 days
        $this->sellDaily($this->a, $hot, 10);

        $row = $this->rows($this->build(), $this->a)['Hot'];
        $this->assertSame('critical', $row['urgency']);
        $this->assertEquals(37, $row['need']);                  // ceil(1 + 10 x 4 - 4)

        $orders = [];
        foreach (range(1, 7) as $h) {
            $orders[] = $this->rows($this->build(horizon: $h), $this->a)['Hot']['need'];
        }
        $this->assertSame([17.0, 27.0, 37.0, 47.0, 57.0, 67.0, 77.0], $orders);
    }

    public function test_out_of_stock_wins_and_without_history_the_rules_decide(): void
    {
        $this->item('Out', $this->a, 0, 5, 15, '2026-10-07');
        $this->item('Slow', $this->a, 3, 5, 12, '2026-10-07');   // below min, no history at all

        $rows = $this->rows($this->build(), $this->a);

        $this->assertSame('out', $rows['Out']['urgency']);
        $this->assertSame('low', $rows['Slow']['urgency']);
        $this->assertSame('min', $rows['Slow']['reason']);
        $this->assertSame('none', $rows['Slow']['confidence']);
        $this->assertNull($rows['Slow']['speed']);
        $this->assertNull($rows['Slow']['cover']);
        $this->assertEquals(9, $rows['Slow']['need']);          // rules only: max - stock
    }

    public function test_the_history_starts_at_the_earlier_of_first_sale_and_first_snapshot(): void
    {
        $new = $this->item('New', $this->a, 4, 1, 10, '2026-10-02');   // first snapshot 5 days ago
        $this->sell($this->a, $new, 10, '2026-10-04 10:00:00');          // 10 / 5 days = 2 a day
        // Only one snapshot (today) but sold since Oct 3: the monitor used to see 0 days of history here.
        $sold = $this->item('Sold', $this->a, 2, 1, 10, '2026-10-07');
        $this->sell($this->a, $sold, 6, '2026-10-03 10:00:00');            // 6 / 4 days = 1.5 a day
        $tooNew = $this->item('TooNew', $this->a, 0, 1, 10, '2026-10-06');
        $this->sell($this->a, $tooNew, 50, '2026-10-06 10:00:00');        // 1 day: not enough

        $rows = $this->rows($this->build(), $this->a);

        $this->assertEqualsWithDelta(2.0, $rows['New']['speed'], 0.001);
        $this->assertEqualsWithDelta(2.0, $rows['New']['cover'], 0.001);
        $this->assertEqualsWithDelta(1.5, $rows['Sold']['speed'], 0.001);
        $this->assertSame('low', $rows['Sold']['confidence']);
        $this->assertNull($rows['TooNew']['speed']);
    }

    public function test_net_sales_since_the_snapshot_storno_cancelled_and_refunds(): void
    {
        $x = $this->item('X', $this->a, 20, 10, 40);
        $this->sell($this->a, $x, 15, '2026-10-07 09:00:00');
        $this->sell($this->a, $x, 99, '2026-10-07 09:10:00', storno: true);
        $this->sell($this->a, $x, 99, '2026-10-07 09:20:00', active: false);
        $this->sell($this->a, $x, 3, '2026-10-07 09:30:00', sell: false);     // refund puts 3 back

        $row = $this->rows($this->build(), $this->a)['X'];

        $this->assertEquals(8, $row['estimated']);              // 20 - 15 + 3
        $this->assertEquals(2, $row['need']);                   // no earlier sales: min 10 - 8
    }

    public function test_piece_items_round_up_to_whole_pieces_and_weight_items_to_a_tenth(): void
    {
        $piece = $this->item('Piece', $this->a, 5, 2, 20);     // 3/day
        $this->sellDaily($this->a, $piece, 3);
        $this->item('Weight', $this->a, 2.55, 3, 6);            // fractional stock: not a piece item, 3 - 2.55

        $rows = $this->rows($this->build(), $this->a);

        $this->assertTrue($rows['Piece']['piece']);
        $this->assertEquals(9, $rows['Piece']['need']);         // ceil(2 + 3 x 4 - 5)
        $this->assertFalse($rows['Weight']['piece']);
        $this->assertEqualsWithDelta(0.5, $rows['Weight']['need'], 1e-9);
    }

    public function test_the_scarce_warehouse_stock_goes_to_the_most_urgent_shops_first(): void
    {
        $i = $this->item('Cola', $this->a, 0, 5, 10, '2026-10-07');   // out of stock in Alpha: needs 10
        ItemOrderRule::query()->create(['shop_id' => $this->b->id, 'item_id' => $i->id, 'min' => 5, 'max' => 10]);
        Stock::query()->create(['shop_id' => $this->b->id, 'item_id' => $i->id, 'qty' => 4, 'stock_date' => '2026-10-07']);
        $this->warehouseHas($i, 12);

        $rec = $this->build();

        $alpha = $this->rows($rec, $this->a)['Cola'];
        $beta = $this->rows($rec, $this->b)['Cola'];
        $this->assertSame('out', $alpha['urgency']);
        $this->assertSame('low', $beta['urgency']);
        $this->assertEquals(10, $alpha['send']);                // the urgent tier is served in full
        $this->assertEquals(0, $alpha['short']);
        $this->assertFalse($alpha['starved']);
        $this->assertEquals(2, $beta['send']);                  // what is left; needs 6
        $this->assertEquals(4, $beta['short']);

        $pick = $rec['pick'][0];
        $this->assertEquals(16, $pick['need']);
        $this->assertEquals(12, $pick['send']);
        $this->assertEquals(4, $pick['short']);
        $this->assertEquals(25.0, $pick['short_pct']);
        $this->assertEquals(12, $pick['available']);
        $this->assertSame(1, $rec['kpi']['short_items']);
        $this->assertSame(1, $rec['kpi']['short_lines']);
    }

    public function test_an_out_of_stock_shop_that_gets_nothing_is_starved(): void
    {
        $i = $this->item('Cola', $this->a, 0, 5, 10, '2026-10-07');
        $this->warehouseHas($i, 0);

        $rec = $this->build();

        $this->assertTrue($this->rows($rec, $this->a)['Cola']['starved']);
        $this->assertTrue($rec['pick'][0]['starved']);
        $this->assertSame(1, $rec['kpi']['starved']);
    }

    public function test_splitting_inside_a_tier_is_proportional_and_leftovers_go_to_the_lowest_cover(): void
    {
        $demands = [
            ['key' => 'a', 'rank' => 2, 'cover' => 2.0, 'need' => 10.0],
            ['key' => 'b', 'rank' => 2, 'cover' => 1.2, 'need' => 10.0],
            ['key' => 'c', 'rank' => 2, 'cover' => 2.5, 'need' => 10.0],
        ];

        $send = OrderRecommendations::splitScarce(10, $demands, 1.0);

        $this->assertEquals(10, array_sum($send));
        $this->assertEquals(4, $send['b']);                     // 3 each, the extra piece to the lowest cover
        $this->assertEquals(3, $send['a']);
        $this->assertEquals(3, $send['c']);

        // A more urgent tier is served in full first; the lower one shares the rest.
        $tiers = [
            ['key' => 'x', 'rank' => 0, 'cover' => 0.0, 'need' => 6.0],
            ['key' => 'y', 'rank' => 3, 'cover' => null, 'need' => 6.0],
        ];
        $send = OrderRecommendations::splitScarce(8, $tiers, 1.0);
        $this->assertEquals(6, $send['x']);
        $this->assertEquals(2, $send['y']);

        // Nothing available: nothing sent; decimals respect the unit.
        $this->assertEquals(0, array_sum(OrderRecommendations::splitScarce(0, $tiers, 1.0)));
        $this->assertEqualsWithDelta(1.1, array_sum(OrderRecommendations::splitScarce(1.17, $tiers, 0.1)), 1e-9);
    }

    public function test_an_item_the_warehouse_does_not_have_is_a_shortage_and_an_unset_warehouse_is_not_checked(): void
    {
        $this->item('Gone', $this->a, 0, 5, 10, '2026-10-07');  // the warehouse has no row for it

        $withWarehouse = $this->build();
        $row = $this->rows($withWarehouse, $this->a)['Gone'];
        $this->assertEquals(0, $row['send']);
        $this->assertEquals(10, $row['short']);
        $this->assertEquals(0, $withWarehouse['pick'][0]['available']);
        $this->assertTrue($withWarehouse['warehouse_configured']);

        $without = $this->build(null, false);
        $row = $this->rows($without, $this->a)['Gone'];
        $this->assertEquals(10, $row['send']);
        $this->assertEquals(0, $row['short']);
        $this->assertNull($without['pick'][0]['available']);
        $this->assertFalse($without['warehouse_configured']);
        $this->assertSame(0, $without['kpi']['short_items']);
    }

    public function test_the_pick_list_aggregates_items_over_shops_sorted_by_urgency(): void
    {
        $cola = $this->item('Cola', $this->a, 0, 5, 10, '2026-10-07');   // out in Alpha
        ItemOrderRule::query()->create(['shop_id' => $this->b->id, 'item_id' => $cola->id, 'min' => 5, 'max' => 20]);
        Stock::query()->create(['shop_id' => $this->b->id, 'item_id' => $cola->id, 'qty' => 4, 'stock_date' => '2026-10-07']);
        $water = $this->item('Water', $this->b, 3, 5, 8, '2026-10-07');  // low in Beta only
        $this->warehouseHas($cola, 100);
        $this->warehouseHas($water, 100);

        $rec = $this->build();

        $this->assertSame(['Cola', 'Water'], array_column($rec['pick'], 'item'));
        $cola = $rec['pick'][0];
        $this->assertEquals(26, $cola['need']);                 // 10 + 16
        $this->assertSame('out', $cola['urgency']);
        $this->assertSame(['Alpha', 'Beta'], array_column($cola['shops'], 'shop'));
        $this->assertSame(2, $rec['kpi']['shops']);
        $this->assertSame(3, $rec['kpi']['lines']);
        $this->assertSame(2, $rec['kpi']['items']);
        $this->assertSame(1, $rec['kpi']['out']);
        $this->assertSame('Alpha', $rec['shops'][0]['name']);   // the shop with the out-of-stock line first
    }

    public function test_the_warehouse_shop_and_unrelated_shops_are_left_out_and_scope_applies(): void
    {
        $this->item('Wh item', $this->wh, 0, 5, 10, '2026-10-07');   // the main warehouse is never replenished
        $this->item('A item', $this->a, 0, 5, 10, '2026-10-07');
        $this->item('B item', $this->b, 0, 5, 10, '2026-10-07');

        $all = $this->build();
        $this->assertSame(['Alpha', 'Beta'], array_column($all['shops'], 'name'));
        $this->assertSame(['A item', 'B item'], collect($all['pick'])->pluck('item')->sort()->values()->all());

        $scoped = $this->build([$this->a->id]);
        $this->assertSame(['Alpha'], array_column($scoped['shops'], 'name'));
        $this->assertSame(['A item'], array_column($scoped['pick'], 'item'));

        $none = $this->build([]);
        $this->assertSame([], $none['shops']);
        $this->assertSame([], $none['pick']);
        $this->assertSame(0, $none['kpi']['lines']);
    }

    public function test_the_current_warehouse_snapshot_is_used_and_nothing_is_written(): void
    {
        $i = $this->item('Cola', $this->a, 0, 5, 10, '2026-10-07');
        Stock::query()->create(['shop_id' => $this->wh->id, 'item_id' => $i->id, 'qty' => 100, 'stock_date' => '2026-10-01']);
        $this->warehouseHas($i, 4);                              // newest snapshot says 4
        $before = Stock::query()->count();

        $row = $this->rows($this->build(), $this->a)['Cola'];

        $this->assertEquals(4, $row['send']);
        $this->assertEquals(6, $row['short']);
        $this->assertSame($before, Stock::query()->count());
    }

    public function test_open_orders_reduce_the_warehouse_stock_and_count_as_in_transit(): void
    {
        $i = $this->item('Cola', $this->a, 0, 5, 10, '2026-10-07');
        $this->warehouseHas($i, 12);
        $order = Order::query()->create(['shop_id' => $this->b->id, 'status' => OrderStatus::Confirmed]);
        $order->items()->create(['item_id' => $i->id, 'qty' => 9]);
        $done = Order::query()->create(['shop_id' => $this->b->id, 'status' => OrderStatus::Fulfilled]);
        $done->items()->create(['item_id' => $i->id, 'qty' => 100]);   // fulfilled: already in the stock figures

        $rec = $this->build();

        $this->assertEquals(3, $rec['pick'][0]['available']);           // 12 - 9 on the open order
        $this->assertEquals(3, $this->rows($rec, $this->a)['Cola']['send']);

        $order2 = Order::query()->create(['shop_id' => $this->a->id, 'status' => OrderStatus::PendingApproval]);
        $order2->items()->create(['item_id' => $i->id, 'qty' => 10]);   // 10 on the way to Alpha itself

        $this->assertArrayNotHasKey('Cola', $this->rows($this->build(), $this->a));
    }
}
