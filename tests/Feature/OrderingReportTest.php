<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use App\Livewire\Ordering\Index;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\Order;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use App\Services\ForecastService;
use App\Support\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** ForecastService (demand model) and the Ordering page / order creation built on it. 2026-10-07 is a Wednesday. */
class OrderingReportTest extends TestCase
{
    use RefreshDatabase;

    private Shop $a;

    private Shop $b;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->a = Shop::factory()->create(['name' => 'Alpha']);
        $this->b = Shop::factory()->create(['name' => 'Beta']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function item(string $name, Shop $shop, float $stock, float $min, float $max, string $firstSeen = '2026-10-07'): Item
    {
        $item = Item::factory()->create(['name' => $name]);
        Stock::query()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => $stock, 'stock_date' => $firstSeen]);
        if ($firstSeen !== '2026-10-07') {
            Stock::query()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => $stock, 'stock_date' => '2026-10-07']);
        }
        ItemOrderRule::query()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'min' => $min, 'max' => $max]);

        return $item;
    }

    private function sell(Shop $shop, Item $item, float $qty, string $at): void
    {
        $receipt = Receipt::factory()->create(['shop_id' => $shop->id, 'created_at' => $at]);
        ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'qty' => $qty]);
    }

    private function sellDaily(Shop $shop, Item $item, float $qty, int $days = 28): void
    {
        for ($i = 1; $i <= $days; $i++) {
            $this->sell($shop, $item, $qty, Carbon::now()->subDays($i)->setTime(11, 0)->toDateTimeString());
        }
    }

    /** @return array<string, mixed> the forecast row of an item in a shop */
    private function row(Shop $shop, string $name, ?array $ids = null): ?array
    {
        $forecast = app(ForecastService::class)->build($ids);

        return collect($forecast['rows'])->first(fn ($r) => $r['shop_id'] === $shop->id && $r['item'] === $name);
    }

    public function test_the_forecast_is_weekday_aware(): void
    {
        // Legacy shape: weekday means only from the 7-day speed window (the window-shape tests are at the end).
        config(['inventory.weekday_shape' => 'legacy', 'inventory.clip_mode' => 'legacy', 'inventory.safety_stock' => false]);
        // 14 sold on each of the last Mondays (Sep 14, 21, 28, Oct 5), nothing otherwise; stock 0.
        $x = $this->item('Mondays', $this->a, 0, 1, 50, '2026-09-01');
        foreach (['2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05'] as $monday) {
            $this->sell($this->a, $x, 14, $monday.' 10:00:00');
        }

        $row = $this->row($this->a, 'Mondays');

        // 7-day window: Oct 5 = 14 -> flat 2; Monday n = 1, lambda = 1/3 -> 14/3 + 2 x 2/3 = 6
        $this->assertEqualsWithDelta(2.0, $row['speed'], 1e-9);
        $this->assertEqualsWithDelta(6.0, $row['f'][5], 0.01);      // Monday Oct 12 = day 5
        $this->assertEqualsWithDelta(1.333, $row['f'][1], 0.01);    // Thursday: no sales that weekday
        $this->assertEqualsWithDelta(array_sum(array_slice($row['f'], 0, 3)) + $row['today_rem'], $row['F'][3], 0.01);
    }

    public function test_out_of_stock_days_are_left_out_of_the_speed(): void
    {
        $x = $this->item('Gappy', $this->a, 8, 2, 50, '2026-10-04');   // snapshots Oct 4..7
        // Sold 4 a day, except Oct 4-6 when the stock snapshot says 0 (nothing to sell).
        foreach (['2026-10-04', '2026-10-05', '2026-10-06'] as $d) {
            Stock::query()->where('item_id', $x->id)->where('stock_date', $d)->delete();
            Stock::query()->create(['shop_id' => $this->a->id, 'item_id' => $x->id, 'qty' => 0, 'stock_date' => $d]);
        }
        for ($i = 4; $i <= 28; $i++) {
            $this->sell($this->a, $x, 4, Carbon::now()->subDays($i)->setTime(11, 0)->toDateTimeString());
        }

        $row = $this->row($this->a, 'Gappy');

        $this->assertSame(3, $row['censored_days']);
        $this->assertEqualsWithDelta(4.0, $row['speed'], 1e-9);    // not 4 x 4 / 7
    }

    public function test_confidence_levels(): void
    {
        $none = $this->item('None', $this->a, 0, 1, 10, '2026-10-06');          // 1 day
        $low = $this->item('Low', $this->a, 0, 1, 10, '2026-10-03');            // 4 days
        $ok = $this->item('Ok', $this->a, 0, 1, 10, '2026-09-27');              // 10 days
        $high = $this->item('High', $this->a, 0, 1, 10, '2026-08-01');          // the whole 28 days
        foreach ([[$none, 1], [$low, 4], [$ok, 10], [$high, 28]] as [$item, $days]) {
            $this->sellDaily($this->a, $item, 1, $days);
            // sales make the item sell, but out-of-stock snapshots would censor: use the snapshot qty > 0
            Stock::query()->where('item_id', $item->id)->update(['qty' => 1]);
        }

        $this->assertSame('none', $this->row($this->a, 'None')['confidence']);
        $this->assertTrue($this->row($this->a, 'None')['rules_only']);
        $this->assertSame('low', $this->row($this->a, 'Low')['confidence']);
        $this->assertSame('ok', $this->row($this->a, 'Ok')['confidence']);
        $this->assertSame('high', $this->row($this->a, 'High')['confidence']);
    }

    public function test_the_order_never_shrinks_with_the_horizon_and_horizon_one_to_seven_are_all_present(): void
    {
        $x = $this->item('Steady', $this->a, 3, 2, 50, '2026-09-01');
        $this->sellDaily($this->a, $x, 2.5);
        $row = $this->row($this->a, 'Steady');

        $this->assertSame(range(1, 7), array_keys($row['order']));
        $previous = -1;
        foreach ($row['order'] as $h => $qty) {
            $this->assertGreaterThanOrEqual($previous, $qty);
            $previous = $qty;
        }
        $this->assertEquals(ceil(2 + $row['F'][3] - 3), $row['order'][3]);
        $this->assertTrue($row['piece']);   // stock, min and max are whole
    }

    public function test_the_default_horizon_comes_from_config_and_is_clamped(): void
    {
        $this->assertSame(3, ForecastService::horizon());
        config(['inventory.order_horizon_days' => 5]);
        $this->assertSame(5, ForecastService::horizon());
        $this->assertSame(7, ForecastService::horizon(99));
        $this->assertSame(1, ForecastService::horizon(0));
    }

    public function test_outlier_days_are_clipped_and_flagged(): void
    {
        $x = $this->item('Spiky', $this->a, 5, 2, 100, '2026-09-01');
        $this->sellDaily($this->a, $x, 2);
        $this->sell($this->a, $x, 500, '2026-10-03 09:00:00');   // one huge day
        $this->sell($this->a, $x, 1, '2026-10-01 09:00:00');
        $this->sell($this->a, $x, 1, '2026-09-30 09:00:00');
        $this->sell($this->a, $x, 1, '2026-09-29 09:00:00');

        $row = $this->row($this->a, 'Spiky') ?? null;

        $this->assertNotNull($row);
        $this->assertTrue($row['clipped']);
        $this->assertLessThan(10, $row['speed']);   // without clipping 7-day mean would be about 74
    }

    public function test_abc_xyz_and_level(): void
    {
        $big = $this->item('Big', $this->a, 0, 1, 100, '2026-09-01');
        $small = $this->item('Small', $this->a, 0, 1, 100, '2026-09-01');
        $this->sellDaily($this->a, $big, 5);
        $this->sellDaily($this->a, $small, 1);
        ReceiptItem::query()->where('item_id', $big->id)->update(['total' => 1000]);
        ReceiptItem::query()->where('item_id', $small->id)->update(['total' => 10]);

        $rowBig = $this->row($this->a, 'Big');
        $rowSmall = $this->row($this->a, 'Small');

        $this->assertSame('A', $rowBig['abc']);
        $this->assertSame('X', $rowBig['xyz']);      // constant demand
        $this->assertSame('out', $rowBig['level']);
        $this->assertSame('C', $rowSmall['abc']);    // the cumulative share passes 95 % only with the last item
        $this->assertSame('2026-10-07', $rowBig['stockout_date']);
    }

    public function test_scope_limits_the_shops_and_the_warehouse_is_excluded(): void
    {
        $this->item('Only A', $this->a, 0, 5, 10);
        $this->item('Only B', $this->b, 0, 5, 10);

        $all = app(ForecastService::class)->build();
        $this->assertSame(['Alpha', 'Beta'], array_column($all['shops'], 'name'));
        $this->assertCount(2, $all['rows']);

        $scoped = app(ForecastService::class)->build([$this->a->id]);
        $this->assertSame(['Alpha'], array_column($scoped['shops'], 'name'));
        $this->assertSame(['Only A'], array_column($scoped['rows'], 'item'));

        $excluded = app(ForecastService::class)->build(null, [$this->a->id]);
        $this->assertSame(['Beta'], array_column($excluded['shops'], 'name'));
    }

    public function test_the_page_shows_tabs_and_switching_the_horizon_does_not_recompute(): void
    {
        $x = $this->item('X', $this->a, 4, 5, 20, '2026-09-01');
        $this->sellDaily($this->a, $x, 1);
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(Index::class)->call('generate');
        $this->assertSame(3, $component->viewData('horizon'));
        $this->assertSame($this->a->id, $component->viewData('active')['id']);
        $before = $component->viewData('active')['rows'][0];
        $this->assertSame('X', $before['item']);

        // A sale lands directly in the DB: the cached forecast doesn't know, whatever the horizon.
        $this->sell($this->a, $x, 3, '2026-10-07 11:00:00');
        $component->set('horizon', 6);
        $after = $component->viewData('active')['rows'][0];
        $this->assertSame(6, $component->viewData('horizon'));
        $this->assertEquals($before['estimated'], $after['estimated']);
        $this->assertGreaterThan($before['need'], $after['need']);

        $component->call('refresh');
        $this->assertLessThan($after['estimated'], $component->viewData('active')['rows'][0]['estimated']);
        $component->set('tab', 'pick')->assertSee(__('Warehouse pick list'));
    }

    public function test_charts_expand_a_line_and_build_the_heat_map(): void
    {
        $x = $this->item('X', $this->a, 4, 5, 20, '2026-09-01');
        $this->sellDaily($this->a, $x, 1);

        $component = Livewire::actingAs(User::factory()->create())->test(Index::class)->call('generate');
        $this->assertNull($component->viewData('detail'));
        $heat = $component->viewData('heat');
        $this->assertSame(['X'], array_column($heat['items'], 'name'));

        $component->call('toggleItem', $x->id);
        $detail = $component->viewData('detail');
        $this->assertSame('X', $detail['item']);
        $this->assertCount(7, $detail['F']);
        $this->assertCount(8, $detail['days']);
        $component->assertSee('data-detail', false);

        $component->call('toggleItem', $x->id);
        $this->assertNull($component->viewData('detail'));
    }

    public function test_the_report_is_admin_only(): void
    {
        $this->item('Only A', $this->a, 0, 5, 10);
        $op = User::factory()->operator()->create();
        $op->shops()->attach($this->a->id);

        $this->actingAs($op)->get('/ordering')->assertForbidden();
        $this->actingAs(User::factory()->monitor()->create())->get('/ordering')->assertStatus(302);
        // Opening the page calculates nothing: the figures appear only after Generate.
        $this->actingAs(User::factory()->create())->get('/ordering')->assertOk()->assertSee(__('Generate'))->assertDontSee('Only A');
        Livewire::actingAs(User::factory()->create())->test(Index::class)->assertSet('generated', false)->assertDontSee('Only A')->call('generate')->assertSet('generated', true)->assertSee('Only A');

        // The nav entry is not offered to operators.
        $this->actingAs($op)->get('/orders')->assertOk()->assertDontSee(route('ordering.index'));
        // And Livewire actions re-check the role.
        Livewire::actingAs($op)->test(Index::class)->assertForbidden();
    }

    public function test_prepare_order_saves_an_editable_draft_with_the_forecast_figures(): void
    {
        $wh = Shop::factory()->create(['name' => 'Main']);
        Warehouse::setShopId($wh->id);
        $cola = $this->item('Cola', $this->a, 0, 5, 10);
        $water = $this->item('Water', $this->a, 0, 5, 20);
        Stock::query()->create(['shop_id' => $wh->id, 'item_id' => $cola->id, 'qty' => 7, 'stock_date' => '2026-10-07']);
        Stock::query()->create(['shop_id' => $wh->id, 'item_id' => $water->id, 'qty' => 100, 'stock_date' => '2026-10-07']);
        $admin = User::factory()->create();
        Queue::fake();

        $component = Livewire::actingAs($admin)->test(Index::class)->call('generate')->call('prepareOrder', $this->a->id);

        $order = Order::query()->with('items')->sole();
        $this->assertSame(OrderStatus::Draft, $order->status);
        $this->assertSame(OrderSyncStatus::NotQueued, $order->sync_status);
        $this->assertNull($order->confirmed_at);
        $this->assertSame($this->a->id, $order->shop_id);
        $this->assertSame($wh->id, $order->warehouse_id);
        $this->assertSame(3, $order->horizon_days);
        $this->assertSame($admin->id, $order->created_by);
        Queue::assertNothingPushed();   // nothing goes to 1C before the shop confirms

        $items = $order->items->keyBy('item_id');
        $this->assertEquals(7, $items[$cola->id]->qty);               // warehouse-limited
        $this->assertEquals(10, $items[$cola->id]->suggested_qty);
        $this->assertEquals(20, $items[$water->id]->qty);
        $this->assertEquals(0, $items[$cola->id]->est);
        $this->assertEquals(5, $items[$cola->id]->min);
        $this->assertFalse($items[$cola->id]->added_manually);
        $this->assertCount(1, $component->get('notice')['created']);
    }

    public function test_preparing_again_adds_only_new_items_and_never_duplicates_or_overwrites(): void
    {
        $cola = $this->item('Cola', $this->a, 0, 5, 10);
        $admin = User::factory()->create();
        $component = Livewire::actingAs($admin)->test(Index::class)->call('generate')->call('prepareOrder', $this->a->id);
        $draft = Order::query()->sole();
        $draft->items()->where('item_id', $cola->id)->update(['qty' => 3]);     // the shop edited it
        $manual = Item::factory()->create();
        $draft->items()->create(['item_id' => $manual->id, 'qty' => 2, 'added_manually' => true]);
        $water = $this->item('Water', $this->a, 0, 5, 20);                       // a new need appears
        $component->call('refresh')->call('prepareOrder', $this->a->id);

        $this->assertSame(1, Order::query()->count());
        $items = $draft->items()->get()->keyBy('item_id');
        $this->assertCount(3, $items);
        $this->assertEquals(3, $items[$cola->id]->qty);                          // edit kept
        $this->assertEquals(10, $items[$cola->id]->suggested_qty);
        $this->assertEquals(2, $items[$manual->id]->qty);
        $this->assertEquals(20, $items[$water->id]->qty);
        $this->assertCount(1, $component->get('notice')['updated']);
    }

    public function test_prepare_all_creates_one_separate_draft_per_shop(): void
    {
        $this->item('Cola', $this->a, 0, 5, 10);
        $this->item('Water', $this->b, 0, 5, 20);

        Livewire::actingAs(User::factory()->create())->test(Index::class)->call('generate')->call('prepareAllOrders');

        $this->assertSame(2, Order::query()->count());
        $this->assertEquals(10, Order::query()->where('shop_id', $this->a->id)->first()->items->sole()->qty);
        $this->assertEquals(20, Order::query()->where('shop_id', $this->b->id)->first()->items->sole()->qty);
        $this->assertSame([OrderStatus::Draft], Order::query()->get()->pluck('status')->unique()->values()->all());
    }

    public function test_open_orders_count_as_in_transit_but_drafts_do_not(): void
    {
        $cola = $this->item('Cola', $this->a, 0, 5, 10);
        $draft = Order::query()->create(['shop_id' => $this->a->id, 'status' => OrderStatus::Draft]);
        $draft->items()->create(['item_id' => $cola->id, 'qty' => 10]);

        $this->assertSame(10.0, (float) $this->row($this->a, 'Cola')['order'][3]);

        $draft->update(['status' => OrderStatus::Confirmed]);

        $this->assertNull($this->row($this->a, 'Cola'));   // 10 on the way: nothing left to order
    }

    // ---------------------------------------------------------------- forecast model v2 (shape / clipping / safety stock)

    /** @param  callable(Carbon, int): float  $qty  quantity sold on the day (i = days back, 1 = yesterday) */
    private function sellPattern(Shop $shop, Item $item, callable $qty, int $days = 28): void
    {
        for ($i = 1; $i <= $days; $i++) {
            $day = Carbon::now()->subDays($i)->setTime(11, 0);
            $q = $qty($day, $i);
            if ($q > 0) {
                $this->sell($shop, $item, $q, $day->toDateTimeString());
            }
        }
    }

    /** Weekend item: 2 on weekdays, 10 on Sat/Sun; the last 7 days sell twice as much (growth). */
    private function weekendItem(string $name, Shop $shop, float $stock = 0, float $max = 200): Item
    {
        $x = $this->item($name, $shop, $stock, 5, $max, '2026-09-01');
        $this->sellPattern($shop, $x, fn (Carbon $d, int $i) => ($d->isWeekend() ? 10 : 2) * ($i <= 7 ? 2 : 1));

        return $x;
    }

    private function legacyFlags(): void
    {
        config(['inventory.weekday_shape' => 'legacy', 'inventory.clip_mode' => 'legacy', 'inventory.safety_stock' => false]);
    }

    public function test_window_shape_vs_legacy_on_a_strong_weekend_series(): void
    {
        $this->weekendItem('Weekend', $this->a);

        $new = $this->row($this->a, 'Weekend');
        config(['inventory.weekday_shape' => 'legacy']);
        $old = $this->row($this->a, 'Weekend');

        // the level (7-day speed) is the same; Saturday Oct 10 = day 3, Monday Oct 12 = day 5
        $this->assertEqualsWithDelta($old['speed'], $new['speed'], 1e-9);
        $this->assertGreaterThan($old['f'][3], $new['f'][3]);   // the weekend peak is better represented
        $this->assertLessThan($old['f'][5], $new['f'][5]);      // and a weekday is not inflated
        // legacy: Saturday appears once in the 7-day window (n = 1): sat/3 + speed x 2/3
        $this->assertEqualsWithDelta($old['speed'] * 2 / 3 + 20 / 3, $old['f'][3], 0.05);
    }

    public function test_clipping_keeps_a_genuine_weekend_peak_but_clips_a_typo(): void
    {
        $x = $this->item('Peaks', $this->a, 0, 5, 500, '2026-09-01');
        $this->sellPattern($this->a, $x, fn (Carbon $d, int $i) => $d->isWeekend() ? 10 : 2);
        $this->sell($this->a, $x, 900, '2026-10-06 09:00:00');   // Tuesday typo, on top of the normal 2

        $new = $this->row($this->a, 'Peaks');
        $this->assertTrue($new['clipped']);
        $this->assertTrue($new['spike']);
        $this->assertGreaterThan(2 * $new['f'][1], $new['f'][3]);   // the Saturday peak survives

        config(['inventory.clip_mode' => 'legacy']);
        $old = $this->row($this->a, 'Peaks');
        $this->assertLessThan($new['f'][3], $old['f'][3]);   // legacy erased the weekend peaks
    }

    public function test_safety_stock_raises_the_target_for_a_volatile_item_not_a_stable_one(): void
    {
        $volatile = $this->item('Volatile', $this->a, 0, 2, 100, '2026-09-01');
        $stable = $this->item('Stable', $this->b, 0, 2, 100, '2026-09-01');
        $pattern = [0, 8, 2, 6];
        $this->sellPattern($this->a, $volatile, fn (Carbon $d, int $i) => $pattern[$i % 4]);
        $this->sellPattern($this->b, $stable, fn () => 4);

        $v = $this->row($this->a, 'Volatile');
        $s = $this->row($this->b, 'Stable');

        $this->assertSame('A', $v['abc']);
        $this->assertSame('A', $s['abc']);
        $this->assertEqualsWithDelta(0.0, $s['sigma'], 1e-6);
        $this->assertGreaterThan(3, $v['sigma']);
        $this->assertEqualsWithDelta(1.65 * $v['sigma'] * sqrt(3), $v['ss'][3], 0.01);
        $this->assertEqualsWithDelta(max(2, $v['ss'][3]) + $v['F'][3], $v['target'][3], 0.01);
        $this->assertEqualsWithDelta(2 + $s['F'][3], $s['target'][3], 0.01);   // SS 0 -> min stays the floor

        config(['inventory.safety_stock' => false]);
        $off = $this->row($this->a, 'Volatile');
        $this->assertNull($off['ss']);
        $this->assertGreaterThan($off['order'][3], $v['order'][3]);
        $this->assertEqualsWithDelta(2 + $off['F'][3], $off['target'][3], 0.01);
    }

    public function test_low_confidence_keeps_the_old_rule(): void
    {
        $x = $this->item('Newcomer', $this->a, 0, 2, 100, '2026-10-03');   // 4 days of history
        $this->sellPattern($this->a, $x, fn (Carbon $d, int $i) => [0, 8, 2, 6][$i % 4], 4);

        $row = $this->row($this->a, 'Newcomer');

        $this->assertSame('low', $row['confidence']);
        $this->assertNull($row['ss']);
        $this->assertEquals(ceil(2 + $row['F'][3]), $row['order'][3]);
        $this->assertEqualsWithDelta(2 + $row['F'][3], $row['target'][3], 0.01);
    }

    public function test_max_overshoot_caps_the_safety_stock_uplift(): void
    {
        $x = $this->item('Capped', $this->a, 0, 2, 15, '2026-09-01');
        $this->sellPattern($this->a, $x, fn (Carbon $d, int $i) => [0, 8, 2, 6][$i % 4]);

        $row = $this->row($this->a, 'Capped');
        $this->assertEqualsWithDelta(22.5, $row['target'][3], 0.01);   // 15 x 1.5
        $this->assertSame(23.0, (float) $row['order'][3]);

        config(['inventory.max_overshoot' => 3]);
        $this->assertGreaterThan(22.5, $this->row($this->a, 'Capped')['target'][3]);

        // the cap only limits the uplift: the plain rule target (min + F) is never reduced
        config(['inventory.max_overshoot' => 0.1]);
        $capped = $this->row($this->a, 'Capped');
        $this->assertEqualsWithDelta(2 + $capped['F'][3], $capped['target'][3], 0.01);
    }

    public function test_the_legacy_flags_restore_the_old_numbers_exactly(): void
    {
        $x = $this->item('Mondays', $this->a, 6, 1, 50, '2026-09-01');
        foreach (['2026-09-14', '2026-09-21', '2026-09-28', '2026-10-05'] as $monday) {
            $this->sell($this->a, $x, 14, $monday.' 10:00:00');
        }
        $this->legacyFlags();

        $row = $this->row($this->a, 'Mondays');

        $this->assertEqualsWithDelta(6.0, $row['f'][5], 0.001);
        $this->assertEqualsWithDelta(1.333, $row['f'][1], 0.001);
        $this->assertSame(3.0, $row['cover_days']);                    // position 6 / speed 2
        $this->assertEquals(ceil(1 + $row['F'][3] - 6), $row['order'][3]);
        $this->assertNull($row['ss']);
    }

    public function test_lead_time_zero_adds_no_horizon_and_a_lead_time_extends_it(): void
    {
        $x = $this->item('Lead', $this->b, 0, 2, 400, '2026-09-01');
        $this->sellPattern($this->b, $x, fn (Carbon $d, int $i) => [0, 8, 2, 6][$i % 4]);

        $zero = $this->row($this->b, 'Lead');
        $this->assertEqualsWithDelta(max(2, $zero['ss'][3]) + $zero['F'][3], $zero['target'][3], 0.01);

        config(['inventory.lead_time_days' => 2]);
        $two = $this->row($this->b, 'Lead');
        $this->assertEqualsWithDelta(1.65 * $zero['sigma'] * sqrt(5), $two['ss'][3], 0.01);
        $this->assertGreaterThan($zero['target'][3], $two['target'][3]);
        $this->assertEqualsWithDelta($zero['F'][5], $two['F'][5], 1e-9);   // the displayed F stays 1..7
        $this->assertEqualsWithDelta(max(2, $two['ss'][3]) + $two['F'][5], $two['target'][3], 0.01);   // F(H + L)
    }

    public function test_cover_days_follow_the_weekday_forecast(): void
    {
        $this->weekendItem('Cover', $this->a, 30);
        $row = $this->row($this->a, 'Cover');

        // Wednesday today, nothing sold yet: the rest of today is a whole day, then f[1], f[2] ...
        $remaining = 30 - $row['today_rem'];
        $expected = 1.0;
        foreach ($row['f'] as $f) {
            if ($remaining <= $f) {
                $expected += $remaining / $f;
                break;
            }
            $remaining -= $f;
            $expected += 1;
        }
        $this->assertEqualsWithDelta($expected, $row['cover_days'], 0.06);
        $this->assertNotEqualsWithDelta($row['position'] / $row['speed'], $row['cover_days'], 0.1);

        config(['inventory.weekday_shape' => 'legacy']);
        $old = $this->row($this->a, 'Cover');
        $this->assertEqualsWithDelta(round(30 / $old['speed'], 1), $old['cover_days'], 1e-9);
    }
}
