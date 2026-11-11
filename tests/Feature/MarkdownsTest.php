<?php

namespace Tests\Feature;

use App\Livewire\Markdowns\Index as MarkdownsPage;
use App\Models\Group;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\ItemPrice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use App\Services\ForecastService;
use App\Services\Markdowns;
use App\Support\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\Concerns\BuildsCustomMonitors;
use Tests\TestCase;

/**
 * Markdown and promo against hand-computed fixtures. "Today" is 2026-10-07 (a Wednesday); the candidate period is
 * 2026-08-08 .. 2026-10-06 (60 days), the discount period 2026-09-01 .. 2026-10-06.
 */
class MarkdownsTest extends TestCase
{
    use BuildsCustomMonitors;
    use RefreshDatabase;

    private const FROM = '2026-08-08';

    private const TO = '2026-10-06';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        app()->setLocale('en');
        config(['markdown.candidates.min_value' => 100]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function retail(): Price
    {
        $id = (int) config('inventory.cost_price_id') + 1;

        return Price::query()->find($id) ?? Price::factory()->create(['id' => $id, 'name' => 'Retail', 'is_sell' => true]);
    }

    /** An item with stock history from $old (so shop and item are old enough), $qty on hand today, a sell price and an optional rule. */
    private function pair(Shop $shop, float $qty, ?float $price = 100, ?array $rule = null, string $old = '2026-05-01', ?string $name = null, array $attrs = []): Item
    {
        $item = Item::factory()->create(($name ? ['name' => $name] : []) + $attrs);
        Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $shop->id, 'qty' => 5, 'stock_date' => $old]);
        Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $shop->id, 'qty' => $qty, 'stock_date' => '2026-10-07']);
        if ($price !== null) {
            ItemPrice::factory()->create(['item_id' => $item->id, 'price_id' => $this->retail()->id, 'value' => $price]);
        }
        if ($rule !== null) {
            ItemOrderRule::factory()->create(['item_id' => $item->id, 'shop_id' => $shop->id, 'min' => $rule[0], 'max' => $rule[1]]);
        }

        return $item;
    }

    private function daily(Shop $shop, Item $item, string $from, string $to, float $qty): void
    {
        for ($d = Carbon::parse($from); $d->lte(Carbon::parse($to)); $d->addDay()) {
            $this->sale($shop, $d->toDateString().' 10:00:00', $qty * 10, [], 1, $item, $qty);
        }
    }

    private function build(array $shops, bool $profit = false): array
    {
        return app(Markdowns::class)->candidates(Carbon::parse(self::FROM), Carbon::parse(self::TO), array_map(fn ($s) => $s instanceof Shop ? $s->id : $s, $shops), $profit);
    }

    private function row(array $report, Item $item, ?Shop $shop = null): ?array
    {
        return collect($report['rows'])->first(fn ($r) => $r['item_id'] === $item->id && ($shop === null || $r['shop_id'] === $shop->id));
    }

    /** One shop with data far enough back; every test that needs a second shop creates it. */
    private function shop(string $name = 'Shop A'): Shop
    {
        return Shop::factory()->create(['name' => $name]);
    }

    // ---------------------------------------------------------------- candidates

    public function test_no_sale_in_the_period_is_a_candidate_with_the_age_based_depth(): void
    {
        $a = $this->shop();
        $never = $this->pair($a, 10, 100, null, '2026-05-01', 'Never sold');
        $sold = $this->pair($a, 10, 100, null, '2026-05-01', 'Sold item');
        $this->sale($a, '2026-09-20 10:00:00', 100, [], 1, $sold, 1);

        $r = $this->build([$a]);

        $row = $this->row($r, $never);
        $this->assertNotNull($row);
        $this->assertSame(['no_sale'], $row['reasons']);
        $this->assertSame(10.0, $row['on_hand']);
        $this->assertEqualsWithDelta(1000.0, $row['value'], 0.001);
        $this->assertNull($row['last_sale']);
        // Never sold and first seen 159 days ago (>= 120): the 30 % rung, by last sale age.
        $this->assertSame(30, $row['depth']);
        $this->assertSame('age', $row['depth_basis']);
        $this->assertNull($this->row($r, $sold), 'sold in the period and no rule: not a candidate');
        $this->assertSame(1, $r['kpi']['candidates']);
        $this->assertEqualsWithDelta(10.0, $r['kpi']['units'], 0.001);
    }

    public function test_a_recently_arrived_never_sold_item_gets_the_no_sale_rung_not_the_old_one(): void
    {
        $a = $this->shop();
        $this->pair($a, 1, 100);                                 // gives the shop its long history
        $item = $this->pair($a, 10, 100, null, '2026-08-20');   // 48 days old: >= 28, < 120

        $this->assertSame(20, $this->row($this->build([$a]), $item)['depth']);
    }

    public function test_an_old_last_sale_gives_the_deepest_rung_and_the_date_is_shown(): void
    {
        $a = $this->shop();
        $item = $this->pair($a, 10, 100);
        $this->sale($a, '2026-05-20 10:00:00', 100, [], 1, $item, 1);   // 140 days before today, outside the period

        $row = $this->row($this->build([$a]), $item);

        $this->assertSame('2026-05-20', $row['last_sale']);
        $this->assertSame(140, $row['days_since']);
        $this->assertSame(30, $row['depth']);
    }

    public function test_cover_above_the_threshold_uses_the_forecast_speed(): void
    {
        $a = $this->shop();
        $item = $this->pair($a, 300, 100, [2, 200]);
        $this->daily($a, $item, '2026-09-20', '2026-10-06', 3);

        $row = $this->row($this->build([$a]), $item);
        $speed = collect(app(ForecastService::class)->buildAll([$a->id], [], now())['rows'])->firstWhere('item_id', $item->id);

        $this->assertNotNull($row);
        $this->assertSame(['cover'], $row['reasons'], 'sold in the period and 300 is not over 3 x 200');
        $this->assertEqualsWithDelta($speed['speed'], $row['speed'], 0.0001, 'the speed is the ForecastService speed');
        $this->assertEqualsWithDelta(3.0, $row['speed'], 0.01);
        $this->assertEqualsWithDelta(100.0, $row['cover'], 0.5);
        $this->assertFalse($row['cover_over_cap']);
        $this->assertSame(10, $row['depth'], 'cover 100 days: the 10 % rung');
        $this->assertSame('cover', $row['depth_basis']);
    }

    public function test_on_hand_over_three_times_the_max_rule_is_a_candidate(): void
    {
        $a = $this->shop();
        $item = $this->pair($a, 40, 100, [2, 10]);
        $this->daily($a, $item, '2026-09-20', '2026-10-06', 5);

        $row = $this->row($this->build([$a]), $item);

        $this->assertSame(['over_max'], $row['reasons'], 'cover is only 8 days');
        $this->assertSame(10, $row['depth']);
        $this->assertSame('over_max', $row['depth_basis']);
    }

    public function test_no_trusted_speed_is_never_infinite_cover(): void
    {
        $a = $this->shop();
        // Stock <= 0 on 26 of the 28 history days: only 2 usable days, so the forecast has no speed (confidence none).
        $censor = function (Item $item) use ($a) {
            for ($d = Carbon::parse('2026-09-11'); $d->lte(Carbon::parse('2026-10-06')); $d->addDay()) {
                Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $a->id, 'qty' => 0, 'stock_date' => $d->toDateString()]);
            }
        };
        // One unit sold inside the period: no speed, so no cover, so not a candidate however much is on hand.
        $few = $this->pair($a, 500, 100, [2, 1000]);
        $censor($few);
        $this->sale($a, '2026-10-06 10:00:00', 10, [], 1, $few, 1);
        // No sale in the period and no speed: a candidate through "no sale" only, cover not computed.
        $old = $this->pair($a, 50, 100, [2, 1000]);
        $censor($old);
        // A TRUSTED speed of 0 is a real "nothing sells": cover above the cap, plus no sale.
        $zero = $this->pair($a, 50, 100, [2, 1000]);

        $r = $this->build([$a]);

        $this->assertNull($this->row($r, $few));
        $row = $this->row($r, $old);
        $this->assertSame(['no_sale'], $row['reasons']);
        $this->assertNull($row['cover']);
        $this->assertNull($row['speed']);
        $this->assertContains('no_speed', $row['flags']);
        $trusted = $this->row($r, $zero);
        $this->assertSame(['no_sale', 'cover'], $trusted['reasons']);
        $this->assertTrue($trusted['cover_over_cap']);
        $this->assertEqualsWithDelta(0.0, $trusted['speed'], 0.0001);
    }

    public function test_low_confidence_rows_are_marked(): void
    {
        $a = $this->shop();
        $item = $this->pair($a, 500, 100, [2, 5000]);
        for ($d = Carbon::parse('2026-09-10'); $d->lte(Carbon::parse('2026-09-29')); $d->addDay()) {
            Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $a->id, 'qty' => 0, 'stock_date' => $d->toDateString()]);
        }
        $this->daily($a, $item, '2026-09-30', '2026-10-04', 2);

        $forecast = collect(app(ForecastService::class)->buildAll([$a->id], [], now())['rows'])->firstWhere('item_id', $item->id);
        $this->assertSame('low', $forecast['confidence']);

        $row = $this->row($this->build([$a]), $item);

        $this->assertNotNull($row);
        $this->assertContains('cover', $row['reasons']);
        $this->assertContains('low_confidence', $row['flags']);
    }

    public function test_cover_is_capped_for_display(): void
    {
        $a = $this->shop();
        $item = $this->pair($a, 5000, 100, [2, 100000]);
        $this->daily($a, $item, '2026-09-20', '2026-10-06', 1);

        $row = $this->row($this->build([$a]), $item);

        $this->assertTrue($row['cover_over_cap']);
        $this->assertEqualsWithDelta(365.0, $row['cover'], 0.001);
        $this->assertSame(20, $row['depth'], 'cover above 180 days');
    }

    public function test_exclusions_new_item_young_shop_not_reported_open_order_and_delist(): void
    {
        $a = $this->shop('Shop A');
        $b = $this->shop('Shop B');

        // New item: first seen 10 days ago.
        $new = $this->pair($a, 10, 100, null, '2026-09-27');
        // Young shop: all its data starts 20 days ago.
        $young = $this->shop('Young shop');
        $youngItem = $this->pair($young, 10, 100, null, '2026-09-17');
        // Not reported: its latest row is older than the shop's latest sync (2026-10-07 from the other items).
        $stale = Item::factory()->create(['name' => 'Stale item']);
        Stock::factory()->create(['item_id' => $stale->id, 'shop_id' => $a->id, 'qty' => 5, 'stock_date' => '2026-05-01']);
        Stock::factory()->create(['item_id' => $stale->id, 'shop_id' => $a->id, 'qty' => 9, 'stock_date' => '2026-10-05']);
        ItemPrice::factory()->create(['item_id' => $stale->id, 'price_id' => $this->retail()->id, 'value' => 100]);
        // Open order of the SAME shop and item: excluded; the same item in the other shop is not.
        $ordered = $this->pair($a, 10, 100, null, '2026-05-01', 'Ordered item');
        Stock::factory()->create(['item_id' => $ordered->id, 'shop_id' => $b->id, 'qty' => 10, 'stock_date' => '2026-10-07']);
        Stock::factory()->create(['item_id' => $ordered->id, 'shop_id' => $b->id, 'qty' => 5, 'stock_date' => '2026-05-01']);
        $order = Order::create(['shop_id' => $a->id, 'status' => 'confirmed', 'confirmed_at' => now()->subHours(2)]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $ordered->id, 'qty' => 5]);
        // A draft is not in transit: still a candidate.
        $draft = $this->pair($a, 10, 100, null, '2026-05-01', 'Drafted item');
        $d = Order::create(['shop_id' => $a->id, 'status' => 'draft']);
        OrderItem::create(['order_id' => $d->id, 'item_id' => $draft->id, 'qty' => 5]);
        // Delist: rule min = max = 0 with stock left.
        $delist = $this->pair($a, 10, 100, [0, 0], '2026-05-01', 'Delist item');
        // A normal candidate so Shop B has history too.
        $plain = $this->pair($b, 10, 100, null, '2026-05-01', 'Plain item');

        $r = $this->build([$a, $b, $young]);

        $this->assertNull($this->row($r, $new));
        $this->assertNull($this->row($r, $youngItem));
        $this->assertNull($this->row($r, $stale));
        $this->assertNull($this->row($r, $ordered, $a), 'open order in the same shop');
        $this->assertNotNull($this->row($r, $ordered, $b), 'the same item in another shop is still judged');
        $this->assertNotNull($this->row($r, $draft));
        $this->assertNotNull($this->row($r, $plain));
        $this->assertNull($this->row($r, $delist), 'listed separately');
        $this->assertSame([$delist->id], array_column($r['delist'], 'item_id'));
        $this->assertSame(1, $r['kpi']['delist']);
        $this->assertSame(1, $r['kpi']['excluded']['new_item']);
        $this->assertSame(1, $r['kpi']['excluded']['young_shop']);
        $this->assertSame(1, $r['kpi']['excluded']['open_order']);
        $this->assertSame(1, $r['kpi']['not_reported'], 'counted, never judged');
    }

    public function test_the_small_stock_guard_and_items_without_a_price(): void
    {
        $a = $this->shop();
        $few = $this->pair($a, 2, 100);          // below min_units 3
        $cheap = $this->pair($a, 10, 5);         // 50 < min_value 100
        $noPrice = $this->pair($a, 10, null);    // kept, shown "no price", never zero
        $ok = $this->pair($a, 10, 100);

        $r = $this->build([$a]);

        $this->assertNull($this->row($r, $few));
        $this->assertNull($this->row($r, $cheap));
        $this->assertSame(2, $r['kpi']['small_stock']);
        $row = $this->row($r, $noPrice);
        $this->assertNull($row['value']);
        $this->assertContains('no_price', $row['flags']);
        $this->assertSame(1, $r['kpi']['no_price']);
        $this->assertEqualsWithDelta(1000.0, $r['kpi']['value'], 0.001, 'only the priced candidate is valued');
        $this->assertNotNull($this->row($r, $ok));
    }

    public function test_the_deeper_rung_wins(): void
    {
        $m = new ReflectionMethod(Markdowns::class, 'applyDepth');
        $m->setAccessible(true);
        $svc = app(Markdowns::class);
        $base = ['item_id' => 1, 'price' => 100.0, 'cover' => null, 'cover_over_cap' => false, 'reasons' => [], 'days_since' => null, 'item_age' => 10, 'flags' => []];

        $cover200 = $m->invoke($svc, ['cover' => 200.0, 'reasons' => ['cover']] + $base, null, [], false, []);
        $this->assertSame(20, $cover200['depth']);
        $this->assertSame('cover', $cover200['depth_basis']);

        $both = $m->invoke($svc, ['cover' => 200.0, 'reasons' => ['cover', 'no_sale'], 'days_since' => 130] + $base, null, [], false, []);
        $this->assertSame(30, $both['depth'], 'last sale 130 days ago beats cover 20 %');
        $this->assertSame('age', $both['depth_basis']);

        $mild = $m->invoke($svc, ['cover' => 100.0, 'reasons' => ['cover', 'no_sale'], 'days_since' => 20] + $base, null, [], false, []);
        $this->assertSame(20, $mild['depth'], 'no sale in the period 20 % beats cover 10 %');

        $over = $m->invoke($svc, ['reasons' => ['over_max']] + $base, null, [], false, []);
        $this->assertSame(10, $over['depth']);
    }

    public function test_the_cost_floor_applies_only_with_profit_access_and_no_cost_figure_leaks_without_it(): void
    {
        $a = $this->shop();
        $tight = $this->pair($a, 10, 100, null, '2026-05-01', 'Tight margin');   // never sold, old: 30 % wanted
        $this->cost($tight, 85);                                                  // allows 15 %
        $unknown = $this->pair($a, 10, 100, null, '2026-05-01', 'No cost known');
        $free = $this->pair($a, 10, 100, null, '2026-05-01', 'Wide margin');
        $this->cost($free, 31337 / 1000);

        $with = $this->build([$a], true);
        $row = $this->row($with, $tight);
        $this->assertSame(15, $row['depth']);
        $this->assertSame(30, $row['cost_limited_from']);
        $this->assertContains('cost_limited', $row['flags']);
        $this->assertEqualsWithDelta(850.0, $row['cost_value'], 0.001);
        $this->assertContains('cost_unknown', $this->row($with, $unknown)['flags']);
        $this->assertSame(30, $this->row($with, $free)['depth'], 'cost does not limit it');
        $this->assertNotContains('cost_limited', $this->row($with, $free)['flags']);
        $this->assertSame(1, $with['kpi']['without_cost']);

        $without = $this->build([$a], false);
        $json = json_encode($without);
        $this->assertSame(30, $this->row($without, $tight)['depth'], 'no cost floor without ProfitAccess');
        $this->assertStringNotContainsString('cost_value', $json);
        $this->assertStringNotContainsString('cost_limited', $json);
        $this->assertStringNotContainsString('cost_unknown', $json);
        $this->assertNull($without['kpi']['value_cost']);
    }

    public function test_transfer_first_names_a_shop_that_sells_the_item(): void
    {
        $a = $this->shop('Shop A');
        $b = $this->shop('Shop B');
        $item = $this->pair($a, 10, 100, null, '2026-05-01', 'Transferable');
        Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $b->id, 'qty' => 5, 'stock_date' => '2026-05-01']);
        Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $b->id, 'qty' => 6, 'stock_date' => '2026-10-07']);
        ItemOrderRule::factory()->create(['item_id' => $item->id, 'shop_id' => $b->id, 'min' => 5, 'max' => 20]);
        $this->daily($b, $item, '2026-09-20', '2026-10-06', 3);

        $need = collect(app(ForecastService::class)->buildAll([$b->id], [], now())['rows'])->firstWhere('item_id', $item->id)['order'][3];
        $this->assertGreaterThanOrEqual(1, $need);

        $row = $this->row($this->build([$a, $b]), $item, $a);

        $this->assertNotNull($row['transfer']);
        $this->assertSame($b->id, $row['transfer']['shop_id']);
        $this->assertSame('Shop B', $row['transfer']['shop']);
        $this->assertEqualsWithDelta(min(10, floor($need)), $row['transfer']['qty'], 0.001);
        $this->assertNull($this->row($this->build([$a, $b]), $item, $b), 'the selling shop is not a candidate');

        // An operator who only has Shop A gets no transfer target and never sees Shop B.
        $only = $this->build([$a]);
        $this->assertNull($this->row($only, $item, $a)['transfer']);
        $this->assertStringNotContainsString('Shop B', json_encode($only));
    }

    public function test_fresh_groups_get_no_depth_and_an_expiry_flag(): void
    {
        $a = $this->shop();
        $group = Group::factory()->create();
        config(['markdown.candidates.fresh_group_ids' => [$group->id]]);
        $fresh = $this->pair($a, 10, 100, null, '2026-05-01', 'Fresh item', ['group_id' => $group->id]);
        $dry = $this->pair($a, 10, 100, null, '2026-05-01', 'Dry item');

        $r = $this->build([$a]);

        $this->assertNull($this->row($r, $fresh)['depth']);
        $this->assertContains('fresh', $this->row($r, $fresh)['flags']);
        $this->assertSame(30, $this->row($r, $dry)['depth']);
    }

    public function test_the_seasonal_hint_needs_last_years_sales(): void
    {
        $a = $this->shop();
        $seasonal = $this->pair($a, 10, 100);
        $this->sale($a, '2025-09-01 10:00:00', 500, [], 1, $seasonal, 5);   // same period last year
        $plain = $this->pair($a, 10, 100);
        $this->sale($a, '2025-08-01 10:00:00', 100, [], 1, $plain, 1);   // last year, but before the same period

        $r = $this->build([$a]);

        $this->assertContains('seasonal', $this->row($r, $seasonal)['flags']);
        $this->assertNotContains('seasonal', $this->row($r, $plain)['flags']);
        $this->assertTrue($r['seasonal_known']);
    }

    public function test_without_last_year_history_the_general_warning_is_shown(): void
    {
        $a = $this->shop();
        $this->pair($a, 10, 100);
        $admin = User::factory()->create();

        $this->assertFalse($this->build([$a])['seasonal_known']);
        Livewire::actingAs($admin)->test(MarkdownsPage::class)
            ->set('dateFrom', self::FROM)->set('dateTo', self::TO)->call('generate')
            ->assertSee('no receipt history for the same period last year', false);
    }

    public function test_disabled_shops_and_the_warehouse_are_left_out(): void
    {
        $a = $this->shop('Shop A');
        $off = Shop::factory()->create(['name' => 'Closed shop', 'is_disabled' => true]);
        $wh = Shop::factory()->create(['name' => 'Main warehouse']);
        Warehouse::setShopId($wh->id);
        $keep = $this->pair($a, 10, 100);
        $this->pair($off, 10, 100);
        $this->pair($wh, 10, 100);

        $r = $this->build([$a, $off, $wh]);

        $this->assertSame([$keep->id], array_column($r['rows'], 'item_id'));
        $this->assertStringNotContainsString('Closed shop', json_encode($r));
        $this->assertStringNotContainsString('Main warehouse', json_encode($r));
    }

    public function test_an_operator_sees_only_his_shops_and_client_ids_are_re_intersected(): void
    {
        $a = $this->shop('Shop A');
        $b = $this->shop('Shop B');
        $mine = $this->pair($a, 10, 100, null, '2026-05-01', 'My slow item');
        $theirs = $this->pair($b, 10, 100, null, '2026-05-01', 'Their slow item');
        $operator = User::factory()->operator()->create();
        $operator->shops()->sync([$a->id]);

        $page = Livewire::actingAs($operator)->test(MarkdownsPage::class)
            ->set('shopIds', [$a->id, $b->id])
            ->set('dateFrom', self::FROM)->set('dateTo', self::TO)
            ->call('generate')
            ->assertSee('My slow item')
            ->assertDontSee('Their slow item')->assertDontSee('Shop B');
        $this->assertStringNotContainsString('Their slow item', $page->html());

        Livewire::actingAs($operator)->test(MarkdownsPage::class)
            ->set('shopIds', [$b->id])
            ->set('dateFrom', self::FROM)->set('dateTo', self::TO)
            ->call('generate')
            ->assertDontSee('Their slow item')->assertDontSee('Shop B');
        $this->assertNotNull($theirs);
        $this->assertNotNull($mine);
    }

    public function test_generate_computes_nothing_before_it_is_pressed_and_every_press_recomputes(): void
    {
        $a = $this->shop();
        $this->pair($a, 10, 100);
        $admin = User::factory()->create();

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        $heavy = function () use (&$queries) {
            return collect($queries)->filter(fn ($sql) => preg_match('/from [`"]?(receipts|stocks|item_order_rules|receipt_items)[`"]?/i', $sql))->count();
        };

        $component = Livewire::actingAs($admin)->test(MarkdownsPage::class)
            ->assertSee('No report yet')->assertSee('Generate')
            ->assertSee('No shelf-life data: candidates are slow stock, not expiry risk.');
        $this->assertSame(0, $heavy(), 'nothing heavy runs before Generate');

        $component->set('dateFrom', self::FROM)->set('dateTo', self::TO)->call('generate')
            ->assertSee('Markdown candidates')
            ->assertSee('No shelf-life data: candidates are slow stock, not expiry risk.');
        $this->assertGreaterThan(0, $heavy());
        $first = $component->get('runKey');
        $before = $heavy();

        $component->call('generate');
        $this->assertNotSame($first, $component->get('runKey'));
        $this->assertGreaterThan($before, $heavy(), 'a second press builds fresh data');
        $component->assertSee('Generate');
    }

    public function test_period_rules(): void
    {
        $admin = User::factory()->create();

        foreach ([['2026-10-05', '2026-10-06'], ['2026-09-30', '2026-10-07'], ['2026-06-01', '2026-10-06']] as [$from, $to]) {
            Livewire::actingAs($admin)->test(MarkdownsPage::class)
                ->set('dateFrom', $from)->set('dateTo', $to)->call('generate')
                ->assertHasErrors('dateTo')->assertSet('generated', false);
        }
        $this->assertSame(60, (int) config('markdown.default_days'));
    }

    public function test_the_page_shows_a_stale_stock_warning_and_no_cost_for_a_user_without_profit_access(): void
    {
        $a = $this->shop();
        $item = $this->pair($a, 10, 100, null, '2026-05-01', 'Plain slow');
        $this->cost($item, 7919);
        $operator = User::factory()->operator()->create();
        $operator->shops()->sync([$a->id]);

        $html = Livewire::actingAs($operator)->test(MarkdownsPage::class)
            ->set('dateFrom', self::FROM)->set('dateTo', self::TO)->call('generate')
            ->assertSee('Plain slow')->assertSee('Value at sell price')->assertDontSee('Value at cost')->html();

        $this->assertStringNotContainsString('7919', $html);
        $this->assertStringNotContainsString('Cost unknown', $html);

        Carbon::setTestNow('2026-10-20 12:00:00');
        Livewire::actingAs($operator)->test(MarkdownsPage::class)
            ->set('dateFrom', '2026-09-01')->set('dateTo', '2026-10-19')->call('generate')
            ->assertSee('more than 3 days old');
    }

    // ------------------------------------------------------- discount effectiveness

    /**
     * Sat/Sun are the busy days: full price 10 a day, weekdays 2. Six weekend days are discounted (qty 12, line sum
     * 120, line total 96 = 20 %); every day 2026-08-18 .. 2026-10-06 is in stock.
     *
     * @param  array<string, mixed>  $o
     */
    private function promo(Shop $shop, Item $item, array $o = []): void
    {
        $o += [
            'disc' => ['2026-09-05', '2026-09-06', '2026-09-12', '2026-09-13', '2026-09-19', '2026-09-20'],
            'discQty' => 12, 'fullQty' => 10, 'weekdayQty' => 2, 'allDisc' => false,
            'noStock' => [], 'zero' => [], 'filler' => 0, 'fillerSkip' => [], 'cashier' => 'Hidden Kassir',
        ];
        $other = $o['filler'] > 0 ? Item::factory()->create(['name' => 'Filler item']) : null;

        for ($d = Carbon::parse('2026-08-18'); $d->lte(Carbon::parse('2026-10-06')); $d->addDay()) {
            $date = $d->toDateString();
            if (! in_array($date, $o['noStock'], true)) {
                Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $shop->id, 'qty' => in_array($date, $o['zero'], true) ? 0 : 50, 'stock_date' => $date]);
            }
            if ($o['allDisc'] || in_array($date, $o['disc'], true)) {
                $qty = (float) $o['discQty'];
                $sum = $qty * 10;
                $total = $sum * 0.8;
            } else {
                $qty = (float) ($d->isWeekend() ? $o['fullQty'] : $o['weekdayQty']);
                $sum = $total = $qty * 10;
            }
            $receipt = $this->sale($shop, $date.' 10:00:00', $total, ['cashier' => $o['cashier']], 1, $item, $qty);
            ReceiptItem::query()->where('receipt_id', $receipt->id)->update(['sum' => $sum, 'total' => $total]);
            if ($other !== null && ! in_array($date, $o['fillerSkip'], true)) {
                for ($i = 0; $i < $o['filler']; $i++) {
                    $this->sale($shop, sprintf('%s 12:%02d:00', $date, $i), 10, [], 1, $other, 1);
                }
            }
        }
    }

    private function discounts(array $shops): array
    {
        return app(Markdowns::class)->discounts(Carbon::parse('2026-09-01'), Carbon::parse('2026-10-06'), array_map(fn ($s) => $s instanceof Shop ? $s->id : $s, $shops));
    }

    private function discRow(array $report, Item $item): ?array
    {
        return collect($report['rows'])->firstWhere('item_id', $item->id);
    }

    public function test_lift_uses_the_full_price_days_of_the_same_weekdays(): void
    {
        $shop = $this->shop();
        $item = Item::factory()->create(['name' => 'Promo item']);
        $this->promo($shop, $item);

        $r = $this->discounts([$shop]);
        $row = $this->discRow($r, $item);

        $this->assertSame('ok', $row['status']);
        $this->assertSame(6, $row['disc_days']);
        // 12 units against the weekend full-price mean of 10 = 1.2. An unweighted baseline over every full-price day
        // (about 4.3 a day, weekdays included) would give 2.8: that is exactly what the weighting prevents.
        $this->assertEqualsWithDelta(10.0, $row['baseline'], 0.001);
        $this->assertEqualsWithDelta(12.0, $row['disc_mean'], 0.001);
        $this->assertEqualsWithDelta(1.2, $row['lift'], 0.001);
        $this->assertEqualsWithDelta(144.0, $row['give_away'], 0.001, '6 days x (120 - 96)');
        $this->assertEqualsWithDelta(20.0, $row['depth'], 0.001);
        $this->assertEqualsWithDelta(10.0, $row['list_price'], 0.001);
        $this->assertEqualsWithDelta(0.2 * 10 * 6 * 10, $row['incremental'], 0.001, '(lift - 1) x baseline x days x list price');
        $this->assertFalse($row['cannibalised'], 'lift is not below 1.2');
        $this->assertFalse($row['rarely_full']);
        $this->assertSame(1, $r['kpi']['pairs']);
        $this->assertSame(1, $r['kpi']['judged']);

        // Episodes: three Saturday + Sunday runs, newest first, each marked as inferred on the page.
        $this->assertCount(3, $row['episodes']);
        $this->assertSame('2026-09-19', $row['episodes'][0]['start']);
        $this->assertSame(2, $row['episodes'][0]['days']);
        $this->assertNotNull($row['episodes'][0]['before']);
        $this->assertEqualsWithDelta(12.0, $row['episodes'][0]['during'], 0.001);
    }

    public function test_the_minimum_number_of_days_gives_na(): void
    {
        $shop = $this->shop();
        $item = Item::factory()->create();
        $this->promo($shop, $item, ['disc' => ['2026-09-05', '2026-09-06']]);

        $row = $this->discRow($this->discounts([$shop]), $item);

        $this->assertSame('na', $row['status']);
        $this->assertSame('days', $row['reason']);
        $this->assertNull($row['lift']);
        $this->assertNull($row['incremental']);
        $this->assertEqualsWithDelta(48.0, $row['give_away'], 0.001, 'the give-away is still shown');
    }

    public function test_cannibalised_needs_a_low_lift_and_a_real_give_away(): void
    {
        $shop = $this->shop();
        $item = Item::factory()->create();
        $this->promo($shop, $item, ['discQty' => 10]);   // no extra units: lift 1.0, give-away 6 x 20 = 120

        config(['markdown.effectiveness.cannibalised_min_giveaway' => 100]);
        $row = $this->discRow($this->discounts([$shop]), $item);
        $this->assertEqualsWithDelta(1.0, $row['lift'], 0.001);
        $this->assertTrue($row['cannibalised']);

        config(['markdown.effectiveness.cannibalised_min_giveaway' => 50000]);
        $this->assertFalse($this->discRow($this->discounts([$shop]), $item)['cannibalised'], 'give-away below the threshold');
    }

    public function test_refunds_of_the_same_day_are_netted_and_storno_lines_are_ignored(): void
    {
        $shop = $this->shop();
        $item = Item::factory()->create();
        $this->promo($shop, $item);
        // 4 units refunded on a discounted day: net 8 instead of 12.
        $refund = $this->sale($shop, '2026-09-05 15:00:00', 40, ['sell' => false], 1, $item, 4);
        ReceiptItem::query()->where('receipt_id', $refund->id)->update(['sum' => 40, 'total' => 40]);
        // A storno line of 100 units on another discounted day must change nothing.
        $receipt = Receipt::query()->whereDate('created_at', '2026-09-12')->where('sell', true)->first();
        ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'storno' => true, 'qty' => 100, 'price' => 10, 'total' => 1000, 'sum' => 1000]);

        $row = $this->discRow($this->discounts([$shop]), $item);

        $this->assertEqualsWithDelta((5 * 12 + 8) / 6, $row['disc_mean'], 0.001);
        $this->assertEqualsWithDelta(((5 * 12 + 8) / 6) / 10, $row['lift'], 0.001);
        $this->assertEqualsWithDelta(144.0, $row['give_away'], 0.001, 'storno lines add no give-away');
    }

    public function test_days_without_stock_or_with_zero_stock_are_skipped(): void
    {
        $shop = $this->shop();
        $item = Item::factory()->create();
        // One discounted day has no stock row (unknown), another a real zero: both leave the lift.
        $this->promo($shop, $item, ['noStock' => ['2026-09-05'], 'zero' => ['2026-09-06']]);

        $row = $this->discRow($this->discounts([$shop]), $item);

        $this->assertSame(4, $row['disc_days']);
        $this->assertEqualsWithDelta(96.0, $row['give_away'], 0.001, '4 days x 24');
    }

    public function test_silent_feed_days_are_left_out(): void
    {
        config(['inventory.feed_silence_min_median' => 3]);
        $shop = $this->shop();
        $item = Item::factory()->create();
        // 6 other receipts every day except on discounted Saturday 2026-09-12: the shop's feed was silent that day.
        $this->promo($shop, $item, ['filler' => 6, 'fillerSkip' => ['2026-09-12']]);

        $r = $this->discounts([$shop]);
        $row = $this->discRow($r, $item);

        $this->assertSame(5, $row['disc_days'], 'the silent shop-day is missing data, not a discounted day');
        $this->assertGreaterThanOrEqual(1, $r['kpi']['silent_shop_days']);
    }

    public function test_rarely_sold_at_full_price_is_labelled_without_the_discount_only_wording(): void
    {
        $shop = $this->shop();
        $item = Item::factory()->create(['name' => 'Always on offer']);
        $this->promo($shop, $item, ['allDisc' => true]);
        $admin = User::factory()->create();

        $row = $this->discRow($this->discounts([$shop]), $item);

        $this->assertTrue($row['rarely_full']);
        $this->assertSame('na', $row['status'], 'no full-price days to compare with');
        $this->assertEqualsWithDelta(100.0, $row['disc_share'], 0.001);

        $html = Livewire::actingAs($admin)->test(MarkdownsPage::class)
            ->set('dateFrom', '2026-09-01')->set('dateTo', '2026-10-06')->call('generate')
            ->call('setTab', 'discounts')
            ->assertSee('Always on offer')->assertSee('Rarely sold at full price')
            ->assertSee('not a recorded markdown', false)->assertSee('cause')->html();
        $this->assertStringNotContainsStringIgnoringCase('discount-only', $html);
        $this->assertStringNotContainsStringIgnoringCase('discount only', $html);
    }

    public function test_discount_output_has_no_cashier_names_or_other_shops_for_an_operator(): void
    {
        $a = $this->shop('Shop A');
        $b = $this->shop('Shop B');
        $mine = Item::factory()->create(['name' => 'Mine on offer']);
        $theirs = Item::factory()->create(['name' => 'Theirs on offer']);
        $this->promo($a, $mine, ['cashier' => 'Zafar Kassirov']);
        $this->promo($b, $theirs, ['cashier' => 'Zafar Kassirov']);

        $report = $this->discounts([$a]);
        $this->assertNotNull($this->discRow($report, $mine));
        $this->assertNull($this->discRow($report, $theirs));
        $this->assertStringNotContainsString('Zafar', json_encode($report));
        $this->assertStringNotContainsString('Shop B', json_encode($report));

        $operator = User::factory()->operator()->create();
        $operator->shops()->sync([$a->id]);
        $html = Livewire::actingAs($operator)->test(MarkdownsPage::class)
            ->set('shopIds', [$a->id, $b->id])
            ->set('dateFrom', '2026-09-01')->set('dateTo', '2026-10-06')->call('generate')
            ->call('setTab', 'discounts')
            ->assertSee('Mine on offer')->assertDontSee('Theirs on offer')->html();
        $this->assertStringNotContainsString('Zafar', $html);
        $this->assertStringNotContainsString('Shop B', $html);
    }
}
