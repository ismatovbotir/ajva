<?php

namespace Tests\Feature;

use App\Livewire\MonitorScreen;
use App\Models\Shop;
use App\Models\User;
use App\Monitor\Comparison;
use App\Monitor\Datasets;
use App\Monitor\Diagram;
use App\Monitor\MonitorData;
use App\Monitor\WidgetCatalog;
use App\Services\ForecastService;
use App\Support\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BuildsCustomMonitors;
use Tests\TestCase;

/**
 * The data side of custom monitors: both baselines against hand-computed fixtures, colour rules, scope and
 * profit/public isolation, and that the shared pass only computes what the layout needs.
 * 2026-10-07 is a Wednesday: yesterday = Tue 10-06, same weekday last week = Wed 09-30.
 */
class CustomMonitorDataTest extends TestCase
{
    use BuildsCustomMonitors;
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

    /**
     * Alpha: today 4 sales of 100 (400), yesterday 12 sales of 50 by 15:00 (600) + one at 16:00 that is after the cut,
     * last week 20 sales of 25 by 15:00 (500) + one at 16:00. Beta only sells today.
     */
    private function seedBaselines(): void
    {
        $this->sales($this->a, '2026-10-07', '09:00:00', 4, 100);
        $this->sales($this->a, '2026-10-06', '09:00:00', 12, 50);
        $this->sale($this->a, '2026-10-06 16:00:00', 999);
        $this->sales($this->a, '2026-09-30', '09:00:00', 20, 25);
        $this->sale($this->a, '2026-09-30 16:00:00', 999);
        $this->sales($this->b, '2026-10-07', '10:00:00', 2, 70);
    }

    public function test_both_baselines_are_cut_at_the_same_time_of_day_and_match_hand_computed_values(): void
    {
        $this->seedBaselines();
        $monitor = $this->customMonitor(['sales_revenue', 'receipt_count', 'avg_check']);

        $result = MonitorData::build($this->scopeFor($monitor));
        $sales = $this->payload($result, 'sales_revenue')['compare'];

        // Today: 4 x 100 + 2 x 70 = 540. Yesterday by 15:00: 12 x 50 = 600. Last week by 15:00: 20 x 25 = 500.
        $this->assertEquals(540, $sales['now']);
        $this->assertEquals(600, $sales['prev_day']);
        $this->assertEquals(500, $sales['same_weekday_lw']);
        $this->assertEqualsWithDelta(-10.0, $sales['a'], 0.001);       // 540 vs 600
        $this->assertEqualsWithDelta(8.0, $sales['b'], 0.001);         // 540 vs 500
        $this->assertSame('warn', $sales['status']);                   // A is below -5 %, nothing below -15 %
        $this->assertSame(__('Yesterday by :time', ['time' => '15:00']), $sales['prev_label']);
        $this->assertSame(__('Last week by :time', ['time' => '15:00']), $sales['lw_label']);
        $this->assertSame('540', $this->payload($result, 'sales_revenue')['value']);

        $count = $this->payload($result, 'receipt_count')['compare'];
        $this->assertEquals([6, 12, 20], [$count['now'], $count['prev_day'], $count['same_weekday_lw']]);
        $this->assertEqualsWithDelta(-50.0, $count['a'], 0.001);
        $this->assertEqualsWithDelta(-70.0, $count['b'], 0.001);
        $this->assertSame('bad', $count['status']);

        $avg = $this->payload($result, 'avg_check')['compare'];
        $this->assertEquals([90.0, 50.0, 25.0], [$avg['now'], $avg['prev_day'], $avg['same_weekday_lw']]);
    }

    public function test_the_shared_receipts_board_carries_the_week_ago_window(): void
    {
        $this->seedBaselines();

        $board = app(\App\Services\ReceiptsMetrics::class)->board(now(), null, false);

        $this->assertSame(20, $board['week']['count']);
        $this->assertEquals(500, $board['week']['sum']);
        $this->assertEqualsWithDelta(8.0, $board['deltas_w']['sum']['pct'], 0.001);
        $this->assertSame(20, $board['shops'][0]['week']['count'] + ($board['shops'][1]['week']['count']));
        // The existing yesterday figures are unchanged by the extra window.
        $this->assertSame(12, $board['yesterday']['count']);

        $dash = app(\App\Services\SalesMetrics::class)->board(now(), null, false);
        $this->assertSame(20, $dash['totals']['w_count']);
        $this->assertEquals(500, $dash['totals']['w_sum']);
    }

    public function test_deltas_are_hidden_when_a_baseline_has_fewer_than_ten_receipts(): void
    {
        $this->sales($this->a, '2026-10-07', '09:00:00', 4, 100);
        $this->sales($this->a, '2026-10-06', '09:00:00', 9, 50);   // 9 baseline receipts: not enough
        $this->sales($this->a, '2026-09-30', '09:00:00', 10, 25);  // exactly 10: enough

        $c = $this->payload(MonitorData::build($this->scopeFor($this->customMonitor(['sales_revenue']))), 'sales_revenue')['compare'];

        $this->assertNull($c['a']);
        $this->assertSame('n/a', $c['a_text']);
        $this->assertNotNull($c['b']);
        $this->assertSame('ok', $c['status'], 'only the usable delta counts: 400 vs 250 is +60 %');
    }

    public function test_the_week_ago_day_without_data_is_flagged_incomplete(): void
    {
        $this->sales($this->a, '2026-10-07', '09:00:00', 4, 100);
        $this->sales($this->a, '2026-10-06', '09:00:00', 12, 50);

        $widget = $this->payload(MonitorData::build($this->scopeFor($this->customMonitor(['receipt_count']))), 'receipt_count');

        $this->assertTrue($widget['incomplete']);
        $this->assertNull($widget['compare']['b']);

        $this->sales($this->a, '2026-09-30', '09:00:00', 3, 10);
        $widget = $this->payload(MonitorData::build($this->scopeFor($this->customMonitor(['receipt_count']))), 'receipt_count');
        $this->assertFalse($widget['incomplete']);
    }

    public function test_colour_rules(): void
    {
        $ok = Comparison::make(100, 100, 104, 20, 20);
        $this->assertSame('ok', $ok['status']);

        $this->assertSame('ok', Comparison::status(-5.0, 3.0), 'exactly -5 % is still green');
        $this->assertSame('warn', Comparison::status(-5.1, 3.0));
        $this->assertSame('warn', Comparison::status(-15.0, -6.0), 'exactly -15 % is still amber');
        $this->assertSame('bad', Comparison::status(-15.1, 2.0));
        $this->assertSame('bad', Comparison::status(4.0, -40.0));
        $this->assertSame('none', Comparison::status(null, null));
        $this->assertSame('ok', Comparison::status(null, 1.0));

        $this->assertNull(Comparison::pct(10, 0, 50), 'zero baseline');
        $this->assertNull(Comparison::pct(10, 5, 9), 'fewer than 10 baseline receipts');
        $this->assertEqualsWithDelta(100.0, Comparison::pct(10, 5, 10), 0.001);

        $this->assertSame('none', Comparison::rateStatus(9.0, 9), 'rates need 10 receipts');
        $this->assertSame('ok', Comparison::rateStatus(1.9, 10));
        $this->assertSame('warn', Comparison::rateStatus(2.0, 10));
        $this->assertSame('bad', Comparison::rateStatus(5.0, 10));

        $this->assertSame('ok', Comparison::marginStatus(30.0, 31.0, 31.9));
        $this->assertSame('warn', Comparison::marginStatus(30.0, 32.0, null));
        $this->assertSame('bad', Comparison::marginStatus(30.0, 35.0, 30.0));
        $this->assertSame('none', Comparison::marginStatus(null, 35.0, 30.0));
    }

    public function test_refund_and_cancelled_rates_use_the_two_and_five_percent_thresholds(): void
    {
        $this->sales($this->a, '2026-10-07', '09:00:00', 20, 100);   // 2000 of sales
        $this->sale($this->a, '2026-10-07 12:00:00', 60, ['sell' => false]);      // 3 % of sales: amber
        $this->sale($this->a, '2026-10-07 12:10:00', 10, ['active' => false]);   // 1 of 22 receipts

        $result = MonitorData::build($this->scopeFor($this->customMonitor(['refund_rate', 'cancelled_rate'])));

        $refund = $this->payload($result, 'refund_rate');
        $this->assertSame('3.0%', $refund['value']);
        $this->assertSame('warn', $refund['status']);
        $this->assertEqualsWithDelta(4.545, $this->payload($result, 'cancelled_rate')['gauge']['value'], 0.01);
        $this->assertSame('warn', $this->payload($result, 'cancelled_rate')['status']);
    }

    public function test_stockouts_cover_and_sync_freshness_rules(): void
    {
        Warehouse::setShopId(null);
        $out = $this->ruledItem($this->a, 0, 5, 20, 'Milk');
        $this->ruledItem($this->a, 15, 5, 20, 'Bread');
        $this->sales($this->a, '2026-10-07', '09:00:00', 3, 10, $out);

        $result = MonitorData::build($this->scopeFor($this->customMonitor(['stockout_count', 'sync_freshness', 'low_cover_items'])));

        $stockouts = $this->payload($result, 'stockout_count');
        $this->assertSame('1', $stockouts['value']);
        $this->assertSame('bad', $stockouts['status'], '1 of 2 ruled items is above 5 %');
        $this->assertStringContainsString('2026-10-07', $stockouts['footer']);
        $this->assertNotNull($stockouts['rule']);

        $sync = $this->payload($result, 'sync_freshness');
        $this->assertSame('ok', $sync['status']);

        $low = $this->payload($result, 'low_cover_items');
        $this->assertSame('Milk', $low['items'][0]['label']);
        $this->assertSame('bad', $low['items'][0]['status']);
    }

    public function test_sync_freshness_thresholds(): void
    {
        foreach ([['2026-10-06', 'ok'], ['2026-10-05', 'warn'], ['2026-10-03', 'bad']] as [$date, $status]) {
            \App\Models\Stock::query()->delete();
            \App\Models\Stock::factory()->create(['shop_id' => $this->a->id, 'stock_date' => $date]);

            $widget = $this->payload(MonitorData::build($this->scopeFor($this->customMonitor(['sync_freshness']))), 'sync_freshness');
            $this->assertSame($status, $widget['status'], $date);
            \Illuminate\Support\Facades\Cache::flush();
        }
    }

    public function test_top_items_compare_with_yesterday_and_last_week_at_the_same_time(): void
    {
        $tea = \App\Models\Item::factory()->create(['name' => 'Tea']);
        $this->sales($this->a, '2026-10-07', '09:00:00', 3, 30, $tea);   // 3 today
        $this->sales($this->a, '2026-10-06', '09:00:00', 10, 30, $tea);  // 10 yesterday by 15:00
        $this->sale($this->a, '2026-10-06 16:00:00', 30, [], 1, $tea);   // after the cut
        $this->sales($this->a, '2026-09-30', '09:00:00', 12, 30, $tea);  // 12 last week

        $widget = $this->payload(MonitorData::build($this->scopeFor($this->customMonitor(['top_items']))), 'top_items');

        $this->assertSame('Tea', $widget['items'][0]['label']);
        $this->assertSame('3', $widget['items'][0]['text']);
        $this->assertSame('▼ -70.0%', $widget['items'][0]['cells'][2]);   // 3 vs 10
        $this->assertSame('▼ -75.0%', $widget['items'][0]['cells'][3]);   // 3 vs 12
    }

    public function test_top_n_is_limited_to_fifteen(): void
    {
        foreach (range(1, 20) as $i) {
            $this->sale($this->a, '2026-10-07 09:'.sprintf('%02d', $i).':00', 10, [], 1, \App\Models\Item::factory()->create());
        }
        $monitor = $this->customMonitor(['top_items']);
        $layout = $monitor->layout;
        $layout[0]['options']['top_n'] = 99;

        $widget = $this->payload(MonitorData::build($this->scopeFor($monitor), $layout), 'top_items');

        $this->assertCount(15, $widget['items']);
    }

    public function test_hourly_sales_has_three_series(): void
    {
        $this->seedBaselines();

        $widget = $this->payload(MonitorData::build($this->scopeFor($this->customMonitor(['hourly_sales']))), 'hourly_sales');

        $this->assertCount(3, $widget['lines']);
        $this->assertSame(['now', 'prev', 'week'], array_column($widget['lines'], 'tone'));
        $this->assertGreaterThan(0, array_sum($widget['lines'][2]['values']), 'the week-ago series carries data');
        $this->assertNull(end($widget['lines'][0]['values']), 'hours still to come have no value for today');
    }

    public function test_profit_widgets_are_absent_without_profit_and_run_no_cost_query(): void
    {
        $item = $this->ruledItem($this->a, 5);
        $this->cost($item, 40);
        $this->sale($this->a, '2026-10-07 10:00:00', 100, [], 1, $item);
        $monitor = $this->customMonitor(['sales_revenue', 'gross_profit', 'margin_pct', 'profit_by_shop']);

        \Illuminate\Support\Facades\DB::enableQueryLog();
        $result = MonitorData::build($this->scopeFor($monitor, null, false));
        $sql = collect(\Illuminate\Support\Facades\DB::getQueryLog())->pluck('query')->implode("\n");

        $this->assertNotNull($this->payload($result, 'sales_revenue'));
        foreach (['gross_profit', 'margin_pct', 'profit_by_shop'] as $key) {
            $this->assertNull($this->payload($result, $key), $key);
        }
        $this->assertStringNotContainsString('item_prices', $sql, 'no cost query without profit permission');
        $this->assertNotContains('profit', $result['datasets']);

        $withProfit = MonitorData::build($this->scopeFor($monitor, null, true));
        $this->assertNotNull($this->payload($withProfit, 'gross_profit'));
        $this->assertContains('profit', $withProfit['datasets']);
    }

    public function test_profit_figures_never_reach_the_rendered_screen_for_users_without_access(): void
    {
        $item = $this->ruledItem($this->a, 5);
        $this->cost($item, 40);
        $this->sale($this->a, '2026-10-07 10:00:00', 100, [], 1, $item);
        $monitor = $this->customMonitor(['sales_revenue', 'gross_profit'], ['show_profit' => true]);

        $operator = User::factory()->operator()->create();
        $operator->shops()->sync([$this->a->id]);
        Livewire::actingAs($operator)->test(MonitorScreen::class, ['monitor' => $monitor])
            ->assertSee(__('Sales total'))
            ->assertDontSee(__('Gross profit'));

        Livewire::actingAs(User::factory()->create())->test(MonitorScreen::class, ['monitor' => $monitor])
            ->assertSee(__('Gross profit'));

        // Public link: the monitor's own show_profit switch decides.
        $monitor->regenerateToken();
        Livewire::test(MonitorScreen::class, ['token' => $monitor->token])->assertSee(__('Gross profit'));
        $monitor->update(['show_profit' => false]);
        Livewire::test(MonitorScreen::class, ['token' => $monitor->token])->assertDontSee(__('Gross profit'));
    }

    public function test_receipt_level_widgets_are_absent_on_public_links(): void
    {
        $this->sale($this->a, '2026-10-07 10:00:00', 100, ['cashier' => 'Secret Cashier']);
        $monitor = $this->customMonitor(['sales_revenue', 'receipt_ticker']);
        $monitor->regenerateToken();

        $public = MonitorData::build($this->scopeFor($monitor, null, false, true));
        $this->assertNull($this->payload($public, 'receipt_ticker'));
        $this->assertNotNull($this->payload($public, 'sales_revenue'));
        $this->assertStringNotContainsString('Secret Cashier', json_encode($public));

        $signedIn = MonitorData::build($this->scopeFor($monitor, null, false, false));
        $this->assertNotNull($this->payload($signedIn, 'receipt_ticker'));
        $this->assertStringNotContainsString('Secret Cashier', json_encode($signedIn), 'no cashier names anywhere');

        Livewire::test(MonitorScreen::class, ['token' => $monitor->token])->assertDontSee(__('Latest receipts'));
    }

    public function test_scope_isolation_between_shops(): void
    {
        $this->sales($this->a, '2026-10-07', '09:00:00', 3, 100);
        $this->sales($this->b, '2026-10-07', '10:00:00', 5, 10);
        $monitor = $this->customMonitor(['sales_revenue', 'shop_leaderboard']);

        $onlyA = MonitorData::build($this->scopeFor($monitor, [$this->a->id]));
        $this->assertSame('300', $this->payload($onlyA, 'sales_revenue')['value']);
        $this->assertSame(['Alpha'], array_column($this->payload($onlyA, 'shop_leaderboard')['items'], 'label'));

        $onlyB = MonitorData::build($this->scopeFor($monitor, [$this->b->id]));
        $this->assertSame('50', $this->payload($onlyB, 'sales_revenue')['value'], 'a cached scope is never served to another');

        $none = MonitorData::build($this->scopeFor($monitor, []));
        $this->assertSame('empty', $this->payload($none, 'sales_revenue')['state']);
    }

    public function test_an_operator_only_sees_their_own_shops_on_the_screen(): void
    {
        $this->sales($this->a, '2026-10-07', '09:00:00', 3, 100);
        $this->sales($this->b, '2026-10-07', '10:00:00', 5, 10);
        $monitor = $this->customMonitor(['shop_leaderboard']);
        $operator = User::factory()->operator()->create();
        $operator->shops()->sync([$this->b->id]);

        Livewire::actingAs($operator)->test(MonitorScreen::class, ['monitor' => $monitor])
            ->assertSee('Beta')
            ->assertDontSee('Alpha');
    }

    public function test_warehouse_shop_is_excluded_from_sales_widgets_and_inventory_uses_it(): void
    {
        $wh = Shop::factory()->create(['name' => 'Central warehouse']);
        Warehouse::setShopId($wh->id);
        $this->sales($wh, '2026-10-07', '09:00:00', 3, 1000);
        $this->sales($this->a, '2026-10-07', '09:00:00', 2, 100);
        $item = $this->ruledItem($this->a, 1, 5, 20, 'Rice');
        \App\Models\Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $wh->id, 'qty' => 6]);

        $result = MonitorData::build($this->scopeFor($this->customMonitor(['sales_revenue', 'shop_leaderboard', 'wh_pick_list', 'wh_shortage', 'wh_cover'])));

        $this->assertSame('200', $this->payload($result, 'sales_revenue')['value']);
        $this->assertSame(['Alpha', 'Beta'], array_column($this->payload($result, 'shop_leaderboard')['items'], 'label'), 'never the warehouse');
        $pick = $this->payload($result, 'wh_pick_list');
        $this->assertSame('Rice', $pick['items'][0]['label']);
        $this->assertSame('6', $pick['items'][0]['cells'][0], 'the warehouse can send its 6 pieces of the 19 needed');
        $this->assertSame('1', $this->payload($result, 'wh_shortage')['value']);
        $this->assertSame('32%', $this->payload($result, 'wh_cover')['value']);
    }

    public function test_the_shared_pass_only_builds_the_datasets_the_layout_needs(): void
    {
        $this->seedBaselines();
        $spy = new class extends ForecastService
        {
            public int $calls = 0;

            public function build(?array $shopIds = null, array $excludeShopIds = [], ?Carbon $now = null): array
            {
                $this->calls++;

                return parent::build($shopIds, $excludeShopIds, $now);
            }
        };
        $this->app->instance(ForecastService::class, $spy);

        $sales = MonitorData::build($this->scopeFor($this->customMonitor(['sales_revenue', 'receipt_count', 'avg_check', 'refund_rate', 'shop_leaderboard'])));
        $this->assertSame(0, $spy->calls, 'a sales-only layout never calls ForecastService');
        $this->assertSame(['receipts'], $sales['datasets'], 'five widgets, one shared dataset');

        \Illuminate\Support\Facades\Cache::flush();
        $mixed = MonitorData::build($this->scopeFor($this->customMonitor(['sales_revenue', 'stockout_count', 'low_cover_items', 'wh_pick_list'])));
        $this->assertSame(1, $spy->calls, 'three inventory widgets share one forecast');
        $this->assertEqualsCanonicalizing(['receipts', 'inventory'], $mixed['datasets']);
    }

    public function test_datasets_are_cached_per_layout_version_and_invalidated_by_new_receipts(): void
    {
        $this->sales($this->a, '2026-10-07', '09:00:00', 2, 100);
        $monitor = $this->customMonitor(['sales_revenue']);

        $this->assertSame('200', $this->payload(MonitorData::build($this->scopeFor($monitor)), 'sales_revenue')['value']);
        $this->sale($this->a, '2026-10-07 11:00:00', 100);
        $this->assertSame('200', $this->payload(MonitorData::build($this->scopeFor($monitor)), 'sales_revenue')['value'], 'served from the cache');

        \App\Support\ShopAccess::bumpSalesVersion();
        $this->assertSame('300', $this->payload(MonitorData::build($this->scopeFor($monitor)), 'sales_revenue')['value']);

        $this->sale($this->a, '2026-10-07 12:00:00', 100);
        $monitor->increment('layout_version');
        $this->assertSame('400', $this->payload(MonitorData::build($this->scopeFor($monitor->fresh())), 'sales_revenue')['value'], 'a new layout version has its own cache');
    }

    public function test_an_unknown_widget_key_renders_a_placeholder_never_an_error(): void
    {
        $monitor = $this->customMonitor(['sales_revenue']);
        $layout = $monitor->layout;
        $layout[] = ['id' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'key' => 'retired_metric', 'diagram' => 'bar', 'x' => 6, 'y' => 0, 'w' => 4, 'h' => 2, 'options' => []];
        $monitor->update(['layout' => $layout]);

        $result = MonitorData::build($this->scopeFor($monitor->fresh()));
        $this->assertSame('removed', $this->payload($result, 'retired_metric')['state']);

        Livewire::actingAs(User::factory()->create())->test(MonitorScreen::class, ['monitor' => $monitor])
            ->assertOk()
            ->assertSee(__('Widget removed'));
    }

    public function test_a_stale_diagram_in_an_old_layout_falls_back_to_the_default(): void
    {
        $monitor = $this->customMonitor(['sales_revenue']);
        $layout = $monitor->layout;
        $layout[0]['diagram'] = 'heatmap';
        $monitor->update(['layout' => $layout]);

        $this->assertSame('kpi', $this->payload(MonitorData::build($this->scopeFor($monitor->fresh())), 'sales_revenue')['diagram']);
    }

    public function test_every_widget_renders_in_every_diagram_for_an_admin_with_profit(): void
    {
        $this->seedBaselines();
        $wh = Shop::factory()->create(['name' => 'Central warehouse']);
        Warehouse::setShopId($wh->id);
        $rice = $this->ruledItem($this->a, 1, 5, 20, 'Rice');
        $this->cost($rice, 10);
        \App\Models\Stock::factory()->create(['item_id' => $rice->id, 'shop_id' => $wh->id, 'qty' => 50]);
        $this->sale($this->a, '2026-10-07 11:00:00', 100, ['sell' => false], 1, $rice);
        $this->sale($this->a, '2026-10-07 11:05:00', 100, ['active' => false]);
        $this->sale($this->a, '2026-10-07 11:10:00', 100, ['discount' => 20], 2, $rice);

        $admin = User::factory()->create();
        foreach (WidgetCatalog::all() as $def) {
            foreach ($def->diagrams as $diagram) {
                $monitor = $this->customMonitor([[$def->key, $diagram->value]], ['name' => $def->key.' '.$diagram->value]);

                $html = Livewire::actingAs($admin)->test(MonitorScreen::class, ['monitor' => $monitor])->assertOk()->html();
                $this->assertStringContainsString('data-widget="'.$def->key.'"', $html, "{$def->key} as {$diagram->value}");
            }
        }
    }

    public function test_the_catalog_only_offers_diagrams_the_data_shape_allows(): void
    {
        foreach (WidgetCatalog::all() as $def) {
            $allowed = Diagram::forShape($def->shape);
            foreach ($def->diagrams as $diagram) {
                $this->assertContains($diagram, $allowed, "{$def->key} offers {$diagram->value}");
            }
            $this->assertContains($def->default, $def->diagrams);
            $this->assertContains($def->cost, ['cheap', 'medium', 'expensive']);
            $this->assertGreaterThanOrEqual($def->min[0], $def->w);
            $this->assertLessThanOrEqual($def->max[0], $def->w);
            $this->assertGreaterThanOrEqual($def->min[1], $def->h);
            if ($def->shape === 'single') {
                $this->assertNotContains(Diagram::Donut, $def->diagrams);
            }
            if (in_array(Diagram::Gauge, $def->diagrams, true)) {
                $this->assertContains($def->key, ['refund_rate', 'cancelled_rate', 'discount_rate', 'tills_active', 'margin_pct', 'wh_cover'], 'gauge is for rates only');
            }
        }
    }

    public function test_datasets_class_reports_what_it_built(): void
    {
        $this->assertTrue(method_exists(Datasets::class, 'built'));
    }
}
