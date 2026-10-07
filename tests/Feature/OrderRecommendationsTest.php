<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemOrderRule;
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

    /** Sales spread over the 14 complete days before today (qty in total). */
    private function sellOverWindow(Shop $shop, Item $item, float $total): void
    {
        $this->sell($shop, $item, $total / 2, '2026-09-30 11:00:00');
        $this->sell($shop, $item, $total / 2, '2026-10-05 11:00:00');
    }

    /** @return array<string, array<string, mixed>> */
    private function rows(array $rec, Shop $shop): array
    {
        return collect(collect($rec['shops'])->firstWhere('id', $shop->id)['rows'])->keyBy('item')->all();
    }

    private function build(?array $ids = null, bool $warehouse = true): array
    {
        return app(OrderRecommendations::class)->build($ids, $warehouse ? $this->wh->id : null);
    }

    public function test_speed_and_cover_recommend_an_item_that_is_above_min(): void
    {
        $fine = $this->item('Fine', $this->a, 30, 5, 20);      // 28 sold / 14 days = 2/day, cover 15 days
        $this->sellOverWindow($this->a, $fine, 28);
        $soon = $this->item('Soon', $this->a, 5, 2, 10);       // 2/day, cover 2.5 days, above min 2
        $this->sellOverWindow($this->a, $soon, 28);

        $rows = $this->rows($this->build(), $this->a);

        $this->assertArrayNotHasKey('Fine', $rows);
        $row = $rows['Soon'];
        $this->assertEqualsWithDelta(2.0, $row['speed'], 0.001);
        $this->assertEqualsWithDelta(2.5, $row['cover'], 0.001);
        $this->assertSame('cover', $row['reason']);
        $this->assertSame('soon', $row['urgency']);
        // target = max(max 10, min(speed 2 x 7 days = 14, 2 x max = 20)) = 14; need = 14 - 5
        $this->assertEquals(9, $row['need']);
    }

    public function test_the_cover_target_is_capped_at_twice_the_max_and_urgency_follows_the_cover(): void
    {
        $hot = $this->item('Hot', $this->a, 4, 1, 10);         // 10/day -> cover 0.4 days
        $this->sellOverWindow($this->a, $hot, 140);

        $row = $this->rows($this->build(), $this->a)['Hot'];

        $this->assertSame('critical', $row['urgency']);
        $this->assertEquals(16, $row['need']);                  // target min(70, 20) = 20, minus 4
    }

    public function test_out_of_stock_wins_and_below_min_without_speed_is_low(): void
    {
        $this->item('Out', $this->a, 0, 5, 15);
        $this->item('Slow', $this->a, 3, 5, 12);                // below min, no sales at all -> speed 0, no cover

        $rows = $this->rows($this->build(), $this->a);

        $this->assertSame('out', $rows['Out']['urgency']);
        $this->assertSame('low', $rows['Slow']['urgency']);
        $this->assertSame('min', $rows['Slow']['reason']);
        $this->assertNull($rows['Slow']['cover']);
        $this->assertEquals(9, $rows['Slow']['need']);
    }

    public function test_the_speed_only_counts_the_days_the_item_could_sell(): void
    {
        $new = $this->item('New', $this->a, 4, 1, 10, '2026-10-02');   // first snapshot 5 days ago
        $this->sell($this->a, $new, 10, '2026-10-04 10:00:00');          // 10 / 5 days = 2 a day, not 10 / 14
        $tooNew = $this->item('TooNew', $this->a, 0, 1, 10, '2026-10-06'); // 1 day of history: below MIN_SPEED_DAYS
        $this->sell($this->a, $tooNew, 50, '2026-10-06 10:00:00');

        $rows = $this->rows($this->build(), $this->a);

        $this->assertEqualsWithDelta(2.0, $rows['New']['speed'], 0.001);
        $this->assertEqualsWithDelta(2.0, $rows['New']['cover'], 0.001);
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
        $this->assertEquals(32, $row['need']);
    }

    public function test_piece_items_round_up_to_whole_pieces_and_weight_items_to_a_tenth(): void
    {
        $piece = $this->item('Piece', $this->a, 5, 2, 20);     // 45 sold -> speed 3.214, target min(22.5, 40) = 22.5 -> need 17.5
        $this->sellOverWindow($this->a, $piece, 45);
        $weight = $this->item('Weight', $this->a, 2.55, 3, 6);  // fractional stock: not a piece item, need 3.45

        $rows = $this->rows($this->build(), $this->a);

        $this->assertTrue($rows['Piece']['piece']);
        $this->assertEquals(18, $rows['Piece']['need']);
        $this->assertFalse($rows['Weight']['piece']);
        $this->assertEqualsWithDelta(3.5, $rows['Weight']['need'], 1e-9);
    }

    public function test_the_scarce_warehouse_stock_goes_to_the_most_urgent_shops_first(): void
    {
        $i = $this->item('Cola', $this->a, 0, 5, 10);          // out of stock in Alpha: needs 10
        ItemOrderRule::query()->create(['shop_id' => $this->b->id, 'item_id' => $i->id, 'min' => 5, 'max' => 10]);
        Stock::query()->create(['shop_id' => $this->b->id, 'item_id' => $i->id, 'qty' => 5, 'stock_date' => '2026-09-01']);
        Stock::query()->create(['shop_id' => $this->b->id, 'item_id' => $i->id, 'qty' => 5, 'stock_date' => '2026-10-07']);
        $this->warehouseHas($i, 12);

        $rec = $this->build();

        $alpha = $this->rows($rec, $this->a)['Cola'];
        $beta = $this->rows($rec, $this->b)['Cola'];
        $this->assertSame('out', $alpha['urgency']);
        $this->assertSame('low', $beta['urgency']);
        $this->assertEquals(10, $alpha['send']);                // the urgent tier is served in full
        $this->assertEquals(0, $alpha['short']);
        $this->assertEquals(2, $beta['send']);                  // what is left
        $this->assertEquals(3, $beta['short']);                 // needs 5

        $pick = $rec['pick'][0];
        $this->assertEquals(15, $pick['need']);
        $this->assertEquals(12, $pick['send']);
        $this->assertEquals(3, $pick['short']);
        $this->assertEquals(12, $pick['available']);
        $this->assertSame(1, $rec['kpi']['short_items']);
        $this->assertSame(1, $rec['kpi']['short_lines']);   // one shortage line (Beta)
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
        $this->item('Gone', $this->a, 0, 5, 10);                // the warehouse has no row for it

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
        $cola = $this->item('Cola', $this->a, 0, 5, 10);        // out in Alpha
        ItemOrderRule::query()->create(['shop_id' => $this->b->id, 'item_id' => $cola->id, 'min' => 5, 'max' => 20]);
        Stock::query()->create(['shop_id' => $this->b->id, 'item_id' => $cola->id, 'qty' => 4, 'stock_date' => '2026-10-07']);
        $this->item('Water', $this->b, 3, 5, 8);                // low in Beta only
        $this->warehouseHas($cola, 100);
        $this->warehouseHas(Item::query()->where('name', 'Water')->first(), 100);

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
        $this->item('Wh item', $this->wh, 0, 5, 10);            // the main warehouse is never replenished
        $this->item('A item', $this->a, 0, 5, 10);
        $this->item('B item', $this->b, 0, 5, 10);

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
        $i = $this->item('Cola', $this->a, 0, 5, 10);
        Stock::query()->create(['shop_id' => $this->wh->id, 'item_id' => $i->id, 'qty' => 100, 'stock_date' => '2026-10-01']);
        $this->warehouseHas($i, 4);                              // newest snapshot says 4
        $before = Stock::query()->count();

        $row = $this->rows($this->build(), $this->a)['Cola'];

        $this->assertEquals(4, $row['send']);
        $this->assertEquals(6, $row['short']);
        $this->assertSame($before, Stock::query()->count());
    }
}
