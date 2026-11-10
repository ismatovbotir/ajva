<?php

namespace Tests\Feature;

use App\Livewire\Analytics\Index as AnalyticsIndex;
use App\Livewire\Dashboard;
use App\Livewire\Items\Show as ItemShow;
use App\Livewire\MonitorScreen;
use App\Livewire\Ordering\Index as OrderingIndex;
use App\Livewire\Shops\Index as ShopsIndex;
use App\Livewire\Shops\Show as ShopShow;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use App\Services\AssortmentAnalysis;
use App\Services\ForecastService;
use App\Services\OrderRecommendations;
use App\Services\UnsoldStock;
use App\Support\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithMonitors;
use Tests\TestCase;

/**
 * inventory.explicit_zero_stock: 1C reports zero itself, so a pair missing from the shop's latest sync is
 * "not reported" (state no_data), never out of stock and never counted as stock. Every screen in both modes
 * (default = explicit, false = legacy inference). Today = 2026-10-07.
 *
 * World: shop S, latest sync 2026-10-07. A (reported 0), C (reported 3), B (only yesterday, qty 0) and D (only
 * yesterday, qty 7); every item has a rule min 2 / max 10.
 */
class ExplicitZeroStockTest extends TestCase
{
    use InteractsWithMonitors;
    use RefreshDatabase;

    private Shop $s;

    /** @var array<int, Receipt> one receipt per day back */
    private array $receipts = [];

    /** @var array<string, Item> */
    private array $items = [];

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->s = Shop::factory()->create(['name' => 'Alpha']);
        foreach (['A' => ['2026-10-07', 0], 'C' => ['2026-10-07', 3], 'B' => ['2026-10-06', 0], 'D' => ['2026-10-06', 7]] as $name => [$date, $qty]) {
            $this->items[$name] = Item::factory()->create(['name' => 'Item '.$name]);
            Stock::query()->create(['shop_id' => $this->s->id, 'item_id' => $this->items[$name]->id, 'qty' => $qty, 'stock_date' => $date]);
            ItemOrderRule::query()->create(['shop_id' => $this->s->id, 'item_id' => $this->items[$name]->id, 'min' => 2, 'max' => 10]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function legacy(): void
    {
        config(['inventory.explicit_zero_stock' => false]);
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    /** One receipt per day carrying a line for every given item (few receipts: the POS factory has a small unique domain). */
    private function sellDaily(Item|array $items, float $qty, int $days = 28): void
    {
        foreach ($items instanceof Item ? [$items] : $items as $item) {
            foreach (range(1, $days) as $i) {
                $r = $this->receipts[$i] ??= Receipt::factory()->create(['shop_id' => $this->s->id, 'created_at' => Carbon::now()->subDays($i)->setTime(11, 0)]);
                ReceiptItem::factory()->create(['receipt_id' => $r->id, 'item_id' => $item->id, 'qty' => $qty, 'total' => $qty * 10]);
            }
        }
    }

    // ---------------------------------------------------------------- the shared helper

    public function test_the_helper_is_the_one_definition_of_reported(): void
    {
        $this->assertTrue(Stock::explicitZero());
        $this->assertSame([$this->s->id => '2026-10-07'], Stock::latestDates());
        $this->assertSame([], Stock::latestDates([999]));
        $this->assertSame([$this->s->id => '2026-10-07'], Stock::latestDates([$this->s->id], '2026-10-07'));
        $this->assertSame([$this->s->id => '2026-10-06'], Stock::latestDates([$this->s->id], '2026-10-06'));

        $this->assertTrue(Stock::isReported('2026-10-07', '2026-10-07'));
        $this->assertFalse(Stock::isReported('2026-10-06', '2026-10-07'));
        $this->assertFalse(Stock::isReported(null, '2026-10-07'));

        $reported = Stock::applyReported(Stock::applyCurrent(DB::table('stocks')))->pluck('item_id')->all();
        $this->assertEqualsCanonicalizing([$this->items['A']->id, $this->items['C']->id], $reported);
        $not = Stock::applyNotReported(Stock::applyCurrent(DB::table('stocks')))->pluck('item_id')->all();
        $this->assertEqualsCanonicalizing([$this->items['B']->id, $this->items['D']->id], $not);

        $this->legacy();
        $this->assertTrue(Stock::isReported('2026-10-06', '2026-10-07'));
        $this->assertCount(4, Stock::applyReported(Stock::applyCurrent(DB::table('stocks')))->get());
        $this->assertCount(0, Stock::applyNotReported(Stock::applyCurrent(DB::table('stocks')))->get());
    }

    // ---------------------------------------------------------------- Dashboard, shops, items, monitor

    public function test_dashboard_counts_only_reported_pairs(): void
    {
        $c = Livewire::actingAs($this->admin())->test(Dashboard::class);
        $this->assertEqualsWithDelta(3.0, $c->viewData('shopRows')[0]['total'], 0.001);      // D (7) is not counted
        $this->assertSame(1, $c->viewData('health')['out_of_stock']);                          // A only, B is unknown
        $this->assertSame(2, $c->viewData('health')['not_reported']);                          // B and D
        $c->assertSee('2 pairs not reported by 1C');
    }

    public function test_dashboard_legacy_keeps_the_last_quantity(): void
    {
        $this->legacy();
        $c = Livewire::actingAs($this->admin())->test(Dashboard::class);

        $this->assertEqualsWithDelta(10.0, $c->viewData('shopRows')[0]['total'], 0.001);
        $this->assertSame(2, $c->viewData('health')['out_of_stock']);
        $this->assertSame(0, $c->viewData('health')['not_reported']);
    }

    public function test_shops_list_and_shop_page(): void
    {
        $shops = Livewire::actingAs($this->admin())->test(ShopsIndex::class)->viewData('shops');
        $this->assertSame(2, (int) $shops->first()->item_count);
        $this->assertEquals(3, $shops->first()->qty_total);

        $stocks = Livewire::actingAs($this->admin())->test(ShopShow::class, ['shop' => $this->s])->viewData('stocks')->keyBy('item_id');
        $this->assertTrue($stocks[$this->items['C']->id]->reported);
        $this->assertFalse($stocks[$this->items['D']->id]->reported);

        $this->legacy();
        $shops = Livewire::actingAs($this->admin())->test(ShopsIndex::class)->viewData('shops');
        $this->assertSame(4, (int) $shops->first()->item_count);
        $this->assertEquals(10, $shops->first()->qty_total);
    }

    public function test_item_page_marks_a_not_reported_shop(): void
    {
        $c = Livewire::actingAs($this->admin())->test(ItemShow::class, ['item' => $this->items['D']]);

        $this->assertFalse($c->viewData('stocks')->first()->reported);
        $c->assertSee('data-not-reported', false)->assertSee('Not reported');

        $c = Livewire::actingAs($this->admin())->test(ItemShow::class, ['item' => $this->items['C']]);
        $this->assertTrue($c->viewData('stocks')->first()->reported);
    }

    public function test_monitor_alerts_ignore_not_reported_pairs_and_legacy_counts_them(): void
    {
        $alerts = $this->screen(MonitorScreen::class, $this->admin())->viewData('alerts');
        $this->assertSame(1, $alerts['below_min']);       // A only
        $this->assertSame(1, $alerts['out_of_stock']);

        $this->legacy();
        \Illuminate\Support\Facades\Cache::flush();
        $alerts = $this->screen(MonitorScreen::class, $this->admin())->viewData('alerts');
        $this->assertSame(2, $alerts['below_min']);       // A and B (qty 0)
        $this->assertSame(2, $alerts['out_of_stock']);
    }

    public function test_analytics_row_of_a_not_reported_pair(): void
    {
        $r = Receipt::factory()->create(['shop_id' => $this->s->id, 'active' => true, 'sell' => true, 'created_at' => now()]);
        foreach (['C', 'D'] as $n) {
            ReceiptItem::factory()->create(['receipt_id' => $r->id, 'item_id' => $this->items[$n]->id, 'qty' => 1, 'storno' => false]);
        }

        $rows = Livewire::actingAs($this->admin())->test(AnalyticsIndex::class)->call('generate')->call('selectShop', $this->s->id)
            ->viewData('rows')->keyBy('item_id');

        $this->assertTrue($rows[$this->items['C']->id]['reported']);
        $this->assertFalse($rows[$this->items['D']->id]['reported']);

        $this->legacy();
        $rows = Livewire::actingAs($this->admin())->test(AnalyticsIndex::class)->call('generate')->call('selectShop', $this->s->id)
            ->viewData('rows')->keyBy('item_id');
        $this->assertTrue($rows[$this->items['D']->id]['reported']);
    }

    // ---------------------------------------------------------------- stock lists and analyses

    public function test_unsold_stock_and_assortment_do_not_count_not_reported_pairs(): void
    {
        $from = Carbon::parse('2026-09-01');
        $to = Carbon::parse('2026-10-06');

        $unsold = app(UnsoldStock::class)->build($from, $to, [$this->s->id]);
        $this->assertSame(1, $unsold['kpi']['unsold_items']);      // C only: D is unknown, not stock
        $this->legacy();
        $unsold = app(UnsoldStock::class)->build($from, $to, [$this->s->id]);
        $this->assertSame(2, $unsold['kpi']['unsold_items']);      // C and D

        // An A-class item whose stock row is missing from the latest sync is not "out of stock".
        $this->sellDaily($this->items['B'], 5);
        $this->assertSame(1, app(AssortmentAnalysis::class)->build($from, $to, [$this->s->id])['kpi']['a_at_risk']);
        config(['inventory.explicit_zero_stock' => true]);
        $this->assertSame(0, app(AssortmentAnalysis::class)->build($from, $to, [$this->s->id])['kpi']['a_at_risk']);
    }

    public function test_mcp_stock_levels_shape(): void
    {
        config(['mcp.token' => 'tok']);
        $call = function (array $args) {
            $response = $this->withToken('tok')->postJson('/api/mcp', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'stock_levels', 'arguments' => $args],
            ]);

            return json_decode($response->json('result.content.0.text'), true);
        };

        $data = $call(['shop_id' => $this->s->id]);
        $rows = collect($data['rows'])->keyBy('item');
        $this->assertTrue($data['explicit_zero_stock']);
        $this->assertSame(0.0, (float) $rows['Item A']['qty']);
        $this->assertTrue($rows['Item A']['reported']);
        $this->assertTrue($rows['Item A']['below_min']);
        $this->assertSame('no_data', $rows['Item B']['state']);
        $this->assertNull($rows['Item D']['qty']);
        $this->assertSame(7.0, (float) $rows['Item D']['last_reported_qty']);
        $this->assertFalse($rows['Item B']['below_min']);

        $names = collect($call(['shop_id' => $this->s->id, 'only_below_min' => true])['rows'])->pluck('item')->all();
        $this->assertSame(['Item A'], $names);

        $this->legacy();
        $names = collect($call(['shop_id' => $this->s->id, 'only_below_min' => true])['rows'])->pluck('item')->sort()->values()->all();
        $this->assertSame(['Item A', 'Item B'], $names);
    }

    // ---------------------------------------------------------------- forecast, ordering and the pick list

    public function test_forecast_leaves_not_reported_pairs_out_and_reports_them(): void
    {
        $this->sellDaily(array_values($this->items), 4);

        $forecast = app(ForecastService::class)->build();
        $listed = collect($forecast['rows'])->pluck('item')->sort()->values()->all();

        $this->assertNotContains('Item B', $listed);
        $this->assertNotContains('Item D', $listed);
        $this->assertContains('Item A', $listed);                    // a reported zero is out and is ordered
        $this->assertSame(2, $forecast['no_data']['count']);
        $this->assertSame([$this->s->id => 2], $forecast['no_data']['by_shop']);
        $this->assertEqualsCanonicalizing(['Item B', 'Item D'], array_column($forecast['no_data']['items'], 'item'));
        foreach ($forecast['rows'] as $row) {
            $this->assertFalse($row['no_data']);
        }

        $plan = app(OrderRecommendations::class)->plan($forecast, null, null);
        $this->assertSame(2, $plan['no_data']['count']);
        $this->assertSame(2, $plan['shops'][0]['no_data']);
        $this->assertNotContains('Item B', array_column($plan['shops'][0]['rows'], 'item'));
    }

    public function test_forecast_legacy_orders_the_old_pair_from_its_last_quantity(): void
    {
        $this->legacy();
        $this->sellDaily(array_values($this->items), 4);

        $forecast = app(ForecastService::class)->build();
        $rows = collect($forecast['rows'])->keyBy('item');

        $this->assertTrue($rows->has('Item B'));                     // out of stock by inference
        $this->assertFalse($rows['Item B']['no_data']);
        $this->assertFalse($rows['Item B']['stale']);                // yesterday is not older than the lookback
        $this->assertSame(0, $forecast['no_data']['count']);
    }

    public function test_a_rule_pair_that_never_had_a_stock_row_is_not_reported(): void
    {
        $ghost = Item::factory()->create(['name' => 'Ghost']);
        ItemOrderRule::query()->create(['shop_id' => $this->s->id, 'item_id' => $ghost->id, 'min' => 1, 'max' => 5]);

        $forecast = app(ForecastService::class)->build();

        $this->assertSame(3, $forecast['no_data']['count']);
        $this->assertContains('Ghost', array_column($forecast['no_data']['items'], 'item'));

        $this->legacy();
        $this->assertSame(0, app(ForecastService::class)->build()['no_data']['count']);
    }

    public function test_the_ordering_page_surfaces_the_not_reported_count(): void
    {
        foreach (['A', 'C'] as $n) {
            $this->sellDaily($this->items[$n], 4);
        }

        Livewire::actingAs($this->admin())->test(OrderingIndex::class)->call('generate')
            ->assertSee('data-no-data', false)
            ->assertSee('2 items were not reported by 1C in the latest stock sync');
    }

    public function test_forecast_is_set_based_for_not_reported_pairs(): void
    {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            app(ForecastService::class)->build();

            return count(DB::getQueryLog());
        };
        $before = $count();
        foreach (range(1, 20) as $i) {
            $item = Item::factory()->create();
            Stock::query()->create(['shop_id' => $this->s->id, 'item_id' => $item->id, 'qty' => 1, 'stock_date' => '2026-10-05']);
            ItemOrderRule::query()->create(['shop_id' => $this->s->id, 'item_id' => $item->id, 'min' => 1, 'max' => 5]);
        }

        $this->assertLessThanOrEqual($before + 1, $count());
    }

    public function test_the_pick_list_ignores_a_warehouse_item_the_latest_sync_did_not_report(): void
    {
        $wh = Shop::factory()->create(['name' => 'Main warehouse']);
        Warehouse::setShopId($wh->id);
        $this->sellDaily($this->items['A'], 4);
        // The warehouse's latest sync (Oct 7) reports a real 0 for C but says nothing about A (last row Oct 6: 500).
        Stock::query()->create(['shop_id' => $wh->id, 'item_id' => $this->items['A']->id, 'qty' => 500, 'stock_date' => '2026-10-06']);
        Stock::query()->create(['shop_id' => $wh->id, 'item_id' => $this->items['C']->id, 'qty' => 0, 'stock_date' => '2026-10-07']);
        $this->sellDaily($this->items['C'], 4);

        $plan = app(OrderRecommendations::class)->build(null, $wh->id);
        $pick = collect($plan['pick'])->keyBy('item');

        $this->assertTrue($pick['Item A']['wh_unknown']);            // not 500 (a guess), not 0 (a guess)
        $this->assertNull($pick['Item A']['available']);
        $this->assertSame(0.0, (float) $pick['Item A']['short']);
        $this->assertFalse($pick['Item C']['wh_unknown']);
        $this->assertEquals(0, $pick['Item C']['available']);        // a real reported zero
        $this->assertGreaterThan(0, $pick['Item C']['short']);
    }
}
