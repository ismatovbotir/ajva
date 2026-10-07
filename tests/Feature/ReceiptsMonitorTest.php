<?php

namespace Tests\Feature;

use App\Enums\MonitorType;
use App\Jobs\ProcessReceiptIngestion;
use App\Livewire\MonitorScreen;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\ItemPrice;
use App\Models\Monitor;
use App\Models\Pos;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\ReceiptPayment;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use App\Services\ReceiptsMetrics;
use App\Support\MonitorScope;
use App\Support\ShopAccess;
use App\Support\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithMonitors;
use Tests\TestCase;

/** The receipts and warehouse TV screens: metrics, scoping, profit gating, caching and rendering. */
class ReceiptsMonitorTest extends TestCase
{
    use InteractsWithMonitors;
    use RefreshDatabase;

    private Shop $a;

    private Shop $b;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 15:00:00');
        $this->a = Shop::factory()->create(['name' => 'Alpha']);
        $this->b = Shop::factory()->create(['name' => 'Beta']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A receipt with $lines positions (plus one storno line that must not count). */
    private function receipt(Shop $shop, string $at, float $total, int $lines = 1, array $attrs = []): Receipt
    {
        $receipt = Receipt::factory()->create(array_merge([
            'shop_id' => $shop->id, 'pos_id' => $attrs['pos_id'] ?? Pos::factory()->create(['shop_id' => $shop->id])->id,
            'active' => true, 'sell' => true, 'total' => $total, 'created_at' => $at,
        ], $attrs));
        ReceiptItem::factory()->count($lines)->create(['receipt_id' => $receipt->id, 'storno' => false, 'total' => $total / max($lines, 1)]);
        ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'storno' => true]);

        return $receipt;
    }

    private function seedDay(): void
    {
        // Today in Alpha: 3 sales (100 + 200 + 300, 1 + 2 + 3 positions), a refund (50), a cancelled receipt (70).
        $this->receipt($this->a, '2026-10-07 10:00:00', 100, 1);
        $this->receipt($this->a, '2026-10-07 11:00:00', 200, 2);
        $this->receipt($this->a, '2026-10-07 11:30:00', 300, 3);
        $this->receipt($this->a, '2026-10-07 12:00:00', 50, 1, ['sell' => false]);
        $this->receipt($this->a, '2026-10-07 13:00:00', 70, 1, ['active' => false]);
        // Yesterday: two sales before 15:00, one after it (not part of the same-time baseline).
        $this->receipt($this->a, '2026-10-06 10:00:00', 100);
        $this->receipt($this->a, '2026-10-06 14:30:00', 100);
        $this->receipt($this->a, '2026-10-06 16:00:00', 500);
    }

    private function board(?array $ids = null, bool $profit = false): array
    {
        return app(ReceiptsMetrics::class)->board(now(), $ids, $profit);
    }

    public function test_totals_averages_basket_and_same_time_baseline(): void
    {
        $this->seedDay();

        $r = $this->board();

        $this->assertSame(3, $r['today']['count']);
        $this->assertEquals(600, $r['today']['sum']);
        $this->assertEquals(200, $r['today']['avg']);
        $this->assertEquals(2.0, $r['today']['basket']);          // 6 positions / 3 receipts, storno lines excluded
        $this->assertSame(2, $r['yesterday']['count']);           // the 16:00 receipt is after the 15:00 cut
        $this->assertEquals(200, $r['yesterday']['sum']);
        $this->assertEquals(100, $r['yesterday']['avg']);
        $this->assertEqualsWithDelta(50.0, $r['deltas']['count']['pct'], 0.001);
        $this->assertEqualsWithDelta(200.0, $r['deltas']['sum']['pct'], 0.001);
        $this->assertEquals(1, $r['deltas']['count']['diff']);
        $this->assertSame('15:00', $r['asOf']);
    }

    public function test_refunds_and_cancelled_are_separate_and_never_in_the_sales(): void
    {
        $this->seedDay();

        $t = $this->board()['today'];

        $this->assertSame(1, $t['refund_count']);
        $this->assertEquals(50, $t['refund_sum']);
        $this->assertSame(1, $t['cancelled_count']);
        $this->assertEquals(70, $t['cancelled_sum']);
        $this->assertSame(5, $t['all']);
        $this->assertEqualsWithDelta(20.0, $t['refund_percent'], 0.001);          // 1 of 5 receipts
        $this->assertEqualsWithDelta(8.333, $t['refund_amount_percent'], 0.001);  // 50 of 600
        $this->assertEqualsWithDelta(20.0, $t['cancelled_percent'], 0.001);
        $this->assertEquals(600, $t['sum']);
    }

    public function test_severity_needs_a_base_and_uses_amount_for_refunds(): void
    {
        $this->seedDay();
        $small = $this->board();
        $this->assertSame('ok', $small['refundSeverity'], '5 receipts are too few to alarm');
        $this->assertSame('ok', $small['cancelledSeverity']);

        foreach (range(1, 10) as $i) {
            $this->receipt($this->b, '2026-10-07 09:'.sprintf('%02d', $i).':00', 100);
        }
        $this->receipt($this->b, '2026-10-07 09:30:00', 100, 1, ['sell' => false]);   // 150 refunded of 1600 = 9.4 %
        $big = $this->board();

        $this->assertSame('high', $big['refundSeverity']);
        $this->assertSame('high', $big['cancelledSeverity']);   // 1 of 17 receipts = 5.9 %

        $svc = app(ReceiptsMetrics::class);
        $this->assertSame('warn', $svc->severity(3.0, 20));
        $this->assertSame('ok', $svc->severity(1.0, 20));
        $this->assertSame('ok', $svc->severity(50.0, 5));
    }

    public function test_hours_peak_shops_and_busiest_quietest(): void
    {
        $this->seedDay();
        $this->receipt($this->b, '2026-10-07 11:10:00', 40);

        $r = $this->board();
        $hours = collect($r['hours'])->keyBy('hour');

        $this->assertSame(['hour' => '11', 'count' => 3], $r['peak']);   // 200, 300 (Alpha) + 40 (Beta)
        $this->assertSame(3, $hours['11']['count']);
        $this->assertSame(1, $hours['10']['y_count']);                 // yesterday 10:00
        $this->assertTrue($hours['15']['current']);
        $this->assertGreaterThanOrEqual(8, count($hours));
        $this->assertNotContains('16', $hours->keys()->all());         // yesterday's later hours are not shown

        $this->assertSame(['Alpha', 'Beta'], array_column($r['shops'], 'name'));   // by sales total
        $this->assertSame('Alpha', $r['busiest']['name']);
        $this->assertSame('Beta', $r['quietest']['name']);
        $this->assertSame(1, $r['shops'][1]['today']['count']);
        $this->assertSame(0, $r['shops'][1]['yesterday']['count']);
        $this->assertNull($r['shops'][1]['count_delta']);               // no baseline -> no percent
    }

    public function test_a_shop_without_sales_is_the_quietest(): void
    {
        $this->receipt($this->a, '2026-10-07 10:00:00', 100);

        $r = $this->board();

        $this->assertSame('Beta', $r['quietest']['name']);
        $this->assertSame(0, $r['quietest']['count']);
        $this->assertNull($this->board([$this->a->id])['quietest'], 'one shop has no busiest/quietest');
    }

    public function test_payment_mix_ticker_and_tills(): void
    {
        $p1 = Pos::factory()->create(['shop_id' => $this->a->id, 'name' => 'Till 1']);
        Pos::factory()->create(['shop_id' => $this->a->id, 'name' => 'Till 2']);
        $r1 = $this->receipt($this->a, '2026-10-07 14:45:00', 100, 1, ['pos_id' => $p1->id]);
        ReceiptPayment::factory()->create(['receipt_id' => $r1->id, 'payment' => 'cash', 'value' => 100]);
        $r2 = $this->receipt($this->a, '2026-10-07 14:50:00', 60, 1, ['sell' => false, 'pos_id' => $p1->id]);
        ReceiptPayment::factory()->create(['receipt_id' => $r2->id, 'payment' => 'card', 'value' => 60]);
        $this->receipt($this->a, '2026-10-07 14:55:00', 30, 1, ['active' => false, 'pos_id' => $p1->id]);
        $this->receipt($this->a, '2026-10-07 13:00:00', 20);          // another till, but outside the 30 minutes

        $r = $this->board();

        $this->assertSame(['cash'], array_column($r['payments'], 'name'), 'refund payments are not part of the sales mix');
        $this->assertSame(['Till 1', 'Till 1', 'Till 1'], array_column(array_slice($r['latest'], 0, 3), 'pos'));
        $this->assertTrue($r['latest'][0]['cancelled']);                // newest first: 14:55 cancelled
        $this->assertTrue($r['latest'][1]['refund']);
        $this->assertSame('cash', $r['latest'][2]['payment']);
        $this->assertSame(['active' => 1, 'total' => 3], $r['tills']);  // Till 1, Till 2 and the till of the 13:00 receipt
    }

    public function test_shop_scope_limits_everything_and_an_empty_scope_shows_nothing(): void
    {
        $this->seedDay();
        $this->receipt($this->b, '2026-10-07 10:00:00', 40);

        $onlyB = $this->board([$this->b->id]);
        $this->assertSame(1, $onlyB['today']['count']);
        $this->assertSame(['Beta'], array_column($onlyB['shops'], 'name'));
        $this->assertSame(0, $onlyB['today']['refund_count']);

        $none = $this->board([]);
        $this->assertSame(0, $none['today']['all']);
        $this->assertSame([], $none['shops']);
        $this->assertSame([], $none['latest']);
        $this->assertNull($none['peak']);
    }

    public function test_profit_is_only_computed_when_allowed(): void
    {
        $item = Item::factory()->create();
        $cost = Price::factory()->create(['id' => config('inventory.cost_price_id'), 'name' => 'Cost', 'is_sell' => false]);
        ItemPrice::factory()->create(['item_id' => $item->id, 'price_id' => $cost->id, 'value' => 10]);
        $receipt = Receipt::factory()->create(['shop_id' => $this->a->id, 'active' => true, 'sell' => true, 'total' => 100, 'created_at' => '2026-10-07 10:00:00']);
        ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'qty' => 2, 'total' => 100, 'storno' => false]);

        $this->assertArrayNotHasKey('profit', $this->board());
        $this->assertEquals(80, $this->board(null, true)['profit']['total']);

        // The screen: public monitor with and without the switch.
        $monitor = Monitor::factory()->withLink()->type(MonitorType::Receipts)->create(['show_profit' => false]);
        $hidden = $this->get('/monitor/'.$monitor->token)->assertOk()->getContent();
        $this->assertStringNotContainsString(__('Margin'), $hidden);
        $this->assertArrayNotHasKey('profit', Livewire::test(MonitorScreen::class, ['token' => $monitor->token])->viewData('r'));

        $monitor->update(['show_profit' => true]);
        $this->get('/monitor/'.$monitor->token)->assertOk()->assertSee(__('Margin'));

        // Signed in: the user's permission, not the monitor switch.
        $signed = Monitor::factory()->type(MonitorType::Receipts)->create(['show_profit' => true]);
        $this->actingAs(User::factory()->operator()->create())->get('/monitors/'.$signed->id)->assertOk()->assertDontSee(__('Margin'));
    }

    public function test_the_screen_renders_signed_in_and_public_with_the_light_theme_hooks(): void
    {
        $this->seedDay();
        $monitor = Monitor::factory()->withLink()->type(MonitorType::Receipts)->create(['name' => 'Receipts TV']);

        $signed = $this->actingAs(User::factory()->create())->get('/monitors/'.$monitor->id)->assertOk()
            ->assertSee('wire:poll.30s', false)
            ->assertSee(__('Receipts by hour'))
            ->assertSee(__('Yesterday by :time', ['time' => '15:00']))
            ->assertSee(__('Refunds'))
            ->assertSee(__('Cancelled'))
            ->assertSee('Alpha')
            ->assertSee('600')
            ->assertDontSee(__('Light theme'));
        $signed->assertSee('data-panel="shops"', false);

        auth()->logout();
        $this->get('/monitor/'.$monitor->token)->assertOk()
            ->assertSee(__('Light theme'))                       // the public light/dark switch
            ->assertSee('bg-slate-900', false)                   // dark utilities that the light theme remaps
            ->assertSee('text-emerald-400', false)
            ->assertSee('data-pages="1"', false);
    }

    public function test_empty_day_and_no_shops_render_friendly_states(): void
    {
        $monitor = Monitor::factory()->type(MonitorType::Receipts)->create();

        $this->actingAs(User::factory()->create())->get('/monitors/'.$monitor->id)->assertOk()
            ->assertSee(__('Waiting for the first sale today'))
            ->assertDontSee('NaN');

        $operator = User::factory()->operator()->create();   // no shops assigned
        $this->actingAs($operator)->get('/monitors/'.$monitor->id)->assertOk()
            ->assertSee(__('No shops are assigned to you. Ask an admin.'));
    }

    public function test_more_shops_than_fit_are_paged(): void
    {
        foreach (range(1, 10) as $i) {
            $shop = Shop::factory()->create(['name' => 'Extra '.$i]);
            $this->receipt($shop, '2026-10-07 10:00:00', 10 * $i);
        }
        $monitor = Monitor::factory()->type(MonitorType::Receipts)->create();

        $html = $this->actingAs(User::factory()->create())->get('/monitors/'.$monitor->id)->assertOk()->getContent();

        $this->assertStringContainsString('data-pages="2"', $html);   // 12 shops, 8 per page
        $this->assertStringContainsString('data-shop-page="1"', $html);
        $this->assertStringContainsString('Extra 10', $html);
    }

    public function test_an_operator_sees_only_the_intersection_of_the_monitor_and_their_shops(): void
    {
        $this->receipt($this->a, '2026-10-07 10:00:00', 100);
        $this->receipt($this->b, '2026-10-07 10:00:00', 900);
        $monitor = $this->executiveMonitor([$this->a->id, $this->b->id], ['type' => MonitorType::Receipts]);

        $operator = User::factory()->operator()->create();
        $operator->shops()->attach($this->a->id);

        $c = Livewire::actingAs($operator)->test(MonitorScreen::class, ['monitor' => $monitor]);
        $this->assertSame(1, $c->viewData('r')['today']['count']);
        $this->assertEquals(100, $c->viewData('r')['today']['sum']);
        $c->assertSee('Alpha')->assertDontSee('Beta');

        $admin = Livewire::actingAs(User::factory()->create())->test(MonitorScreen::class, ['monitor' => $monitor]);
        $this->assertEquals(1000, $admin->viewData('r')['today']['sum']);
    }

    public function test_the_receipts_cache_is_scoped_and_cleared_when_a_receipt_is_ingested(): void
    {
        $shop = $this->a;
        $monitor = $this->executiveMonitor([$shop->id], ['type' => MonitorType::Receipts]);
        $key = fn (bool $profit) => (new MonitorScope($monitor, [$shop->id], $profit, false, now()))->cacheKey('receipts');

        $c = Livewire::actingAs(User::factory()->create())->test(MonitorScreen::class, ['monitor' => $monitor]);
        $this->assertTrue(Cache::has($key(true)));
        $this->assertNotSame($key(true), $key(false), 'profit and no-profit variants never share an entry');

        // A receipt written behind the cache's back is not visible until the sales version moves.
        $this->receipt($shop, '2026-10-07 14:00:00', 10);
        $c->call('$refresh');
        $this->assertSame(0, $c->viewData('r')['today']['count']);

        ProcessReceiptIngestion::dispatchSync([
            'shop' => $shop->id, 'pos' => 1, 'number' => 'R-1',
            'openDate' => now()->format('d.m.y'), 'openTime' => '14:30:00', 'total' => 25,
            'positions' => [['item' => ['id' => Item::factory()->create()->id], 'qty' => 1, 'totalSum' => 25]],
        ], 1);
        $this->assertFalse(Cache::has($key(true)));
        $c->call('$refresh');
        $this->assertGreaterThanOrEqual(1, $c->viewData('r')['today']['count']);
    }

    // ---- warehouse screen -------------------------------------------------------------------------------------

    private function warehouseSetup(): Shop
    {
        $wh = Shop::factory()->create(['name' => 'Main warehouse']);
        Warehouse::setShopId($wh->id);
        $cola = Item::factory()->create(['name' => 'Cola']);
        Stock::query()->create(['shop_id' => $this->a->id, 'item_id' => $cola->id, 'qty' => 0, 'stock_date' => '2026-10-07']);
        ItemOrderRule::query()->create(['shop_id' => $this->a->id, 'item_id' => $cola->id, 'min' => 5, 'max' => 10]);
        $fanta = Item::factory()->create(['name' => 'Fanta']);
        Stock::query()->create(['shop_id' => $this->b->id, 'item_id' => $fanta->id, 'qty' => 1, 'stock_date' => '2026-10-07']);
        ItemOrderRule::query()->create(['shop_id' => $this->b->id, 'item_id' => $fanta->id, 'min' => 5, 'max' => 8]);
        Stock::query()->create(['shop_id' => $wh->id, 'item_id' => $cola->id, 'qty' => 4, 'stock_date' => '2026-10-07']);
        Stock::query()->create(['shop_id' => $wh->id, 'item_id' => $fanta->id, 'qty' => 50, 'stock_date' => '2026-10-07']);

        return $wh;
    }

    public function test_the_warehouse_screen_shows_cards_pick_list_and_shortages(): void
    {
        $this->warehouseSetup();
        $monitor = Monitor::factory()->withLink()->type(MonitorType::Warehouse)->create();

        $signed = $this->actingAs(User::factory()->create())->get('/monitors/'.$monitor->id)->assertOk();
        $signed->assertSee(__('Pick list'))
            ->assertSee(__('Deliveries by shop'))
            ->assertSee('Alpha')->assertSee('Beta')
            ->assertSee('Cola')->assertSee('Fanta')
            ->assertSee(__('warehouse has'))
            ->assertSee(__('Out of stock'))
            ->assertDontSee('Main warehouse</h3>', false)       // the warehouse is no shop card
            ->assertDontSee(__('The main warehouse is not set. An admin can choose it in Settings > Warehouse.'));
        $html = $signed->getContent();
        $this->assertStringContainsString('short', $html);        // Cola: needs 10, the warehouse has 4

        auth()->logout();
        $this->get('/monitor/'.$monitor->token)->assertOk()
            ->assertSee(__('Light theme'))
            ->assertSee('Cola')
            ->assertSee('bg-red-500', false)
            ->assertSee('data-panel="pick"', false);

        $c = Livewire::test(MonitorScreen::class, ['token' => $monitor->token]);
        $this->assertSame(1, $c->viewData('rec')['kpi']['short_items']);
        $this->assertSame('Main warehouse', $c->viewData('warehouseName'));
    }

    public function test_the_warehouse_screen_without_a_warehouse_and_when_nothing_is_needed(): void
    {
        $item = Item::factory()->create(['name' => 'Cola']);
        Stock::query()->create(['shop_id' => $this->a->id, 'item_id' => $item->id, 'qty' => 0, 'stock_date' => '2026-10-07']);
        ItemOrderRule::query()->create(['shop_id' => $this->a->id, 'item_id' => $item->id, 'min' => 5, 'max' => 10]);
        $monitor = Monitor::factory()->type(MonitorType::Warehouse)->create();

        $this->actingAs(User::factory()->create())->get('/monitors/'.$monitor->id)->assertOk()
            ->assertSee(__('The main warehouse is not set. An admin can choose it in Settings > Warehouse.'))
            ->assertSee('Cola');                                   // per-shop needs are still shown

        $empty = Monitor::factory()->type(MonitorType::Warehouse)->create();
        ItemOrderRule::query()->delete();
        Cache::flush();
        $this->actingAs(User::factory()->create())->get('/monitors/'.$empty->id)->assertOk()
            ->assertSee(__('Nothing to deliver'));
    }

    public function test_the_warehouse_screen_respects_the_monitor_shops_and_the_operators_shops(): void
    {
        $this->warehouseSetup();
        $monitor = $this->executiveMonitor([], ['type' => MonitorType::Warehouse]);   // all shops (minus the warehouse)

        $operator = User::factory()->operator()->create();
        $operator->shops()->attach($this->a->id);

        $c = Livewire::actingAs($operator)->test(MonitorScreen::class, ['monitor' => $monitor]);
        $this->assertSame([$this->a->id], array_column($c->viewData('rec')['shops'], 'id'));
        $c->assertSee('Cola')->assertDontSee('Fanta')->assertDontSee('Beta');

        $admin = Livewire::actingAs(User::factory()->create())->test(MonitorScreen::class, ['monitor' => $monitor]);
        $this->assertCount(2, $admin->viewData('rec')['shops']);

        // Separate cache entries per scope, and the key includes the warehouse choice.
        $key = fn (?array $ids) => (new MonitorScope($monitor, $ids, false, false, now()))->cacheKey('warehouse.w'.Warehouse::shopId());
        $this->assertNotSame($key([$this->a->id]), $key(null));
    }

    public function test_no_profit_or_cost_data_reaches_the_warehouse_payload(): void
    {
        $this->warehouseSetup();
        $monitor = Monitor::factory()->type(MonitorType::Warehouse)->create(['show_profit' => true]);

        $c = Livewire::actingAs(User::factory()->create())->test(MonitorScreen::class, ['monitor' => $monitor]);

        $this->assertStringNotContainsString('profit', strtolower(json_encode($c->viewData('rec'))));
        $this->assertStringNotContainsString('cost', strtolower(json_encode($c->viewData('rec'))));
    }
}
