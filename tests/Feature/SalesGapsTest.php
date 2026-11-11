<?php

namespace Tests\Feature;

use App\Livewire\SalesGaps\Index as SalesGapsPage;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\ItemPrice;
use App\Models\Pos;
use App\Models\Price;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use App\Services\SalesGaps;
use App\Support\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsCustomMonitors;
use Tests\TestCase;

/**
 * Sales gaps against hand-computed fixtures. "Today" is 2026-10-07 (a Wednesday), the default test window is the
 * 7 whole days 2026-09-30 .. 2026-10-06. Thresholds are lowered through config so the fixtures stay small.
 */
class SalesGapsTest extends TestCase
{
    use BuildsCustomMonitors;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        app()->setLocale('en');
        config([
            'sales_gaps.min_receipts' => 10,
            'sales_gaps.card.min_chain_card_receipts' => 10,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function build(array $shops, string $from = '2026-09-30', string $to = '2026-10-06'): array
    {
        return app(SalesGaps::class)->build(Carbon::parse($from), Carbon::parse($to), array_map(fn ($s) => $s instanceof Shop ? $s->id : $s, $shops));
    }

    private function row(array $report, Shop $shop): array
    {
        return collect($report['shops'])->firstWhere('id', $shop->id);
    }

    /** $n sale receipts of $total each, spread over the 7 days 2026-09-30 .. 2026-10-06. */
    private function fill(Shop $shop, int $n, float $total, array $attrs = [], int $lines = 1, ?Item $item = null, float $qty = 1): void
    {
        for ($i = 0; $i < $n; $i++) {
            $day = Carbon::parse('2026-09-30')->addDays($i % 7)->toDateString();
            $this->sale($shop, sprintf('%s 10:%02d:00', $day, $i % 60), $total, $attrs, $lines, $item, $qty);
        }
    }

    /** Five shops with constant checks 100 .. 140, 14 receipts each; shop E also has three huge receipts. */
    private function chain(): array
    {
        $shops = [];
        foreach (['A' => 100, 'B' => 110, 'C' => 120, 'D' => 130, 'E' => 140] as $name => $total) {
            $shops[$name] = Shop::factory()->create(['name' => "Shop {$name}"]);
            $this->fill($shops[$name], 14, $total, [], $name === 'B' ? 2 : 1);
        }
        foreach ([0, 1, 2] as $i) {
            $this->sale($shops['E'], '2026-10-0'.($i + 1).' 15:00:00', 10000);
        }

        return $shops;
    }

    public function test_check_gap_formulas_trimmed_mean_and_cap(): void
    {
        $s = $this->chain();
        $s['A']->update(['area_m2' => 100]);
        // A refund on a day of shop C is netted against that day's sales only.
        $this->sale($s['C'], '2026-10-01 18:00:00', 50, ['sell' => false]);

        $r = $this->build($s);

        // Trimmed checks 100 / 110 / 120 / 130 / 140: shop E's three 10 000 receipts are the chain's top 5 % (3 of 73),
        // the cut is 140 and they are left out, so its trimmed check is exactly 140. Five qualifying shops: benchmark = P75 = 130.
        $this->assertSame('p75', $r['benchmarks']['check']['basis']);
        $this->assertEqualsWithDelta(130.0, $r['benchmarks']['check']['value'], 0.001);
        $this->assertSame(5, $r['benchmarks']['check']['shops']);
        $this->assertEqualsWithDelta(140.0, $r['benchmarks']['cut'], 0.001, 'largest total outside the top 5 %');

        $a = $this->row($r, $s['A']);
        $this->assertSame('ok', $a['check']['status']);
        $this->assertEqualsWithDelta(100.0, $a['check']['trim_check'], 0.001);
        $this->assertEqualsWithDelta(30 / 130 * 100, $a['check']['gap_pct'], 0.001);
        $this->assertTrue($a['check']['flag']);
        // 0.3 x (130 - 100) x 2 receipts a day x 30 = 540, capped at 5 % of 200 x 30 = 300.
        $this->assertEqualsWithDelta(540.0, $a['check']['recoverable'], 0.001);
        $this->assertEqualsWithDelta(300.0, $a['check']['recoverable_capped'], 0.001);
        $this->assertEqualsWithDelta(200.0, $a['sales_per_day'], 0.001);
        $this->assertEqualsWithDelta(2.0, $a['receipts_per_day'], 0.001);
        $this->assertEqualsWithDelta(1.0, $a['basket'], 0.001);
        $this->assertEqualsWithDelta(60.0, $a['sales_per_m2'], 0.001, '200 a day x 30 / 100 m2');
        $this->assertEqualsWithDelta(0.6, $a['receipts_per_m2'], 0.001);
        $this->assertTrue($r['kpi']['has_area']);

        $b = $this->row($r, $s['B']);
        $this->assertTrue($b['check']['flag'], '15.4 % below');
        $this->assertEqualsWithDelta(2.0, $b['basket'], 0.001, 'two lines per receipt');

        $c = $this->row($r, $s['C']);
        $this->assertFalse($c['check']['flag'], '7.7 % is under the 8 % flag');
        $this->assertNull($c['sales_per_m2'], 'no area filled in: no per-m2 figure');
        $this->assertEqualsWithDelta((14 * 120 - 50) / 7, $c['sales_per_day'], 0.001, 'same-day refund netted');

        $d = $this->row($r, $s['D']);
        $this->assertEqualsWithDelta(0.0, $d['check']['gap_pct'], 0.001);
        $e = $this->row($r, $s['E']);
        $this->assertEqualsWithDelta(140.0, $e['check']['trim_check'], 0.001, 'huge receipts do not move the trimmed check');
        $this->assertSame(0.0, $e['check']['recoverable'], 'above the chain: nothing to recover');
        $this->assertGreaterThan(140.0, $e['avg_check'], 'the plain average check is distorted, the trimmed one is not');

        // Sorted by the largest lever: B (capped at 5 % of 220 x 30 = 330) before A (300).
        $this->assertEqualsWithDelta(330.0, $b['check']['recoverable_capped'], 0.001);
        $this->assertSame($s['B']->id, $r['shops'][0]['id']);
        $this->assertSame('check', $r['shops'][0]['top_action']);
        $this->assertSame($s['A']->id, $r['shops'][1]['id']);
    }

    public function test_a_shop_under_the_minimum_is_not_judged(): void
    {
        $s = $this->chain();
        $small = Shop::factory()->create(['name' => 'Small shop']);
        $this->fill($small, 5, 50);

        $row = $this->row($this->build([...$s, $small]), $small);

        $this->assertSame('na', $row['check']['status']);
        $this->assertSame('receipts', $row['check']['reason']);
        $this->assertNull($row['check']['gap_pct']);
        $this->assertNull($row['check']['recoverable']);
        $this->assertFalse($row['check']['flag']);
        $this->assertSame('na', $row['card']['status']);
    }

    public function test_fewer_than_five_shops_use_the_median_and_one_shop_has_no_benchmark(): void
    {
        $shops = [];
        foreach ([100, 120, 140] as $i => $total) {
            $shops[$i] = Shop::factory()->create();
            $this->fill($shops[$i], 14, $total);
        }

        $r = $this->build($shops);
        $this->assertSame('median', $r['benchmarks']['check']['basis']);
        $this->assertEqualsWithDelta(120.0, $r['benchmarks']['check']['value'], 0.001);
        $this->assertEqualsWithDelta(16.667, $this->row($r, $shops[0])['check']['gap_pct'], 0.001);

        $solo = $this->build([$shops[0]]);
        $this->assertSame('median', $solo['benchmarks']['check']['basis'], 'the benchmark is chain-wide: the other shops still count');

    }

    public function test_a_single_qualifying_shop_has_no_benchmark(): void
    {
        $shop = Shop::factory()->create();
        $this->fill($shop, 14, 100);

        $r = $this->build([$shop]);

        $this->assertNull($r['benchmarks']['check']['value']);
        $this->assertSame('benchmark', $r['shops'][0]['check']['reason']);
        $this->assertFalse($r['shops'][0]['check']['flag']);
    }

    public function test_a_feed_silent_day_is_left_out_and_counted(): void
    {
        config(['inventory.feed_silence_min_median' => 3]);
        $shop = Shop::factory()->create();
        // Four receipts every day for 28 days, except 2026-10-03 (a Saturday) when nothing arrived.
        for ($d = Carbon::parse('2026-09-09'); $d->lte(Carbon::parse('2026-10-06')); $d->addDay()) {
            if ($d->toDateString() === '2026-10-03') {
                continue;
            }
            for ($i = 0; $i < 4; $i++) {
                $this->sale($shop, $d->toDateString().' 1'.$i.':00:00', 100);
            }
        }

        $row = $this->row($this->build([$shop]), $shop);

        $this->assertSame(1, $row['silent_days']);
        $this->assertSame(6, $row['days_used']);
        $this->assertSame(24, $row['receipts']);
        $this->assertEqualsWithDelta(4.0, $row['receipts_per_day'], 0.001, '24 receipts over 6 usable days, not 7');
        $this->assertEqualsWithDelta(400.0, $row['sales_per_day'], 0.001);

        config(['inventory.feed_aware_censoring' => false]);
        $off = $this->row($this->build([$shop]), $shop);
        $this->assertSame(0, $off['silent_days']);
        $this->assertSame(7, $off['days_used']);
    }

    public function test_card_share_target_uplift_and_the_chain_conditions(): void
    {
        $shops = [];
        foreach ([0, 2, 4, 6, 8] as $i => $cards) {
            $shops[$i] = Shop::factory()->create();
            $this->fill($shops[$i], 14 - $cards, 100);
            $this->fill($shops[$i], $cards, 200, ['aos' => ['card' => '111']]);
        }

        $r = $this->build($shops);
        $bk = $r['benchmarks']['card'];

        // Shares 0, 2/14, 4/14, 6/14, 8/14: P75 = 6/14. Chain card check 200, anonymous 100.
        $this->assertNull($bk['reason']);
        $this->assertSame('p75', $bk['basis']);
        $this->assertEqualsWithDelta(6 / 14, $bk['value'], 0.0001);

        $a = $this->row($r, $shops[0]);
        $this->assertSame('ok', $a['card']['status']);
        $this->assertEqualsWithDelta(0.0, $a['card']['share'], 0.001);
        $this->assertTrue($a['card']['flag']);
        // 0.5 damping x (200 / 100 - 1) x (6/14 - 0) x 2 receipts a day x 30 x 100 anonymous check.
        $this->assertEqualsWithDelta(0.5 * 1 * (6 / 14) * 2 * 30 * 100, $a['card']['uplift'], 0.01);

        $top = $this->row($r, $shops[4]);
        $this->assertFalse($top['card']['flag'], 'above the target');
        $this->assertEqualsWithDelta(0.0, $top['card']['uplift'], 0.001);

        // The chain needs enough card receipts, else nothing is shown at all.
        config(['sales_gaps.card.min_chain_card_receipts' => 1000]);
        $none = $this->build($shops);
        $this->assertSame('chain_card_receipts', $none['benchmarks']['card']['reason']);
        $this->assertSame('na', $this->row($none, $shops[0])['card']['status']);
        $this->assertNull($this->row($none, $shops[0])['card']['share']);
    }

    public function test_card_lever_is_hidden_when_card_receipts_are_not_larger(): void
    {
        $shops = [];
        foreach ([0, 2, 4, 6, 8] as $i => $cards) {
            $shops[$i] = Shop::factory()->create();
            $this->fill($shops[$i], 14 - $cards, 200);
            $this->fill($shops[$i], $cards, 100, ['aos' => ['card' => '111']]);
        }

        $r = $this->build($shops);

        $this->assertSame('card_check_not_higher', $r['benchmarks']['card']['reason']);
        $this->assertSame('na', $this->row($r, $shops[0])['card']['status']);
    }

    public function test_availability_reuses_the_forecast_and_values_only_trusted_pairs(): void
    {
        $costId = (int) config('inventory.cost_price_id');
        $retail = Price::factory()->create(['id' => $costId + 1, 'name' => 'Retail', 'is_sell' => true]);

        // Shop 1: item X sold 2 a day on 09-23 .. 10-03 at 10 each, out of stock on 10-04 .. 10-07 (3 censored days
        // before today, so 11 usable days). Realised price 10 -> 2 x 10 x 0.7 = 14 a day, 3 days out in the window = 42.
        $one = Shop::factory()->create(['name' => 'Shop One']);
        $x = Item::factory()->create(['name' => 'Item X']);
        ItemOrderRule::factory()->create(['item_id' => $x->id, 'shop_id' => $one->id, 'min' => 5, 'max' => 20]);
        for ($d = Carbon::parse('2026-09-23'); $d->lte(Carbon::parse('2026-10-03')); $d->addDay()) {
            $this->sale($one, $d->toDateString().' 10:00:00', 20, [], 1, $x, 2);
        }
        foreach (['2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07'] as $date) {
            Stock::factory()->create(['item_id' => $x->id, 'shop_id' => $one->id, 'qty' => 0, 'stock_date' => $date]);
        }
        // Item Y has a rule but its stock row is older than the shop's latest sync: not reported, only counted.
        $y = Item::factory()->create(['name' => 'Item Y']);
        ItemOrderRule::factory()->create(['item_id' => $y->id, 'shop_id' => $one->id, 'min' => 5, 'max' => 20]);
        Stock::factory()->create(['item_id' => $y->id, 'shop_id' => $one->id, 'qty' => 0, 'stock_date' => '2026-10-05']);

        // Shop 2: item W is out but has only two days of sales: confidence none, unknown, no money.
        $two = Shop::factory()->create(['name' => 'Shop Two']);
        $w = Item::factory()->create(['name' => 'Item W']);
        ItemOrderRule::factory()->create(['item_id' => $w->id, 'shop_id' => $two->id, 'min' => 5, 'max' => 20]);
        $this->sale($two, '2026-10-05 10:00:00', 20, [], 1, $w, 2);
        $this->sale($two, '2026-10-06 10:00:00', 20, [], 1, $w, 2);
        Stock::factory()->create(['item_id' => $w->id, 'shop_id' => $two->id, 'qty' => 0, 'stock_date' => '2026-10-07']);

        // Shop 3: item V sold 09-16 .. 09-29, out 09-30 .. 10-07, no sale inside the window: the price falls back to
        // the lowest selling price (12), never the cost price (31 337).
        $three = Shop::factory()->create(['name' => 'Shop Three']);
        $v = Item::factory()->create(['name' => 'Item V']);
        ItemOrderRule::factory()->create(['item_id' => $v->id, 'shop_id' => $three->id, 'min' => 5, 'max' => 20]);
        for ($d = Carbon::parse('2026-09-16'); $d->lte(Carbon::parse('2026-09-29')); $d->addDay()) {
            $this->sale($three, $d->toDateString().' 10:00:00', 20, [], 1, $v, 2);
        }
        for ($d = Carbon::parse('2026-09-30'); $d->lte(Carbon::parse('2026-10-07')); $d->addDay()) {
            Stock::factory()->create(['item_id' => $v->id, 'shop_id' => $three->id, 'qty' => 0, 'stock_date' => $d->toDateString()]);
        }
        $this->cost($v, 31337);
        ItemPrice::factory()->create(['item_id' => $v->id, 'price_id' => $retail->id, 'value' => 12]);

        $r = $this->build([$one, $two, $three]);

        $a = $this->row($r, $one)['availability'];
        $this->assertSame(1, $a['out_a']);
        $this->assertSame(1, $a['valued']);
        $this->assertSame(1, $a['not_reported'], 'item Y is counted, never valued');
        $this->assertEqualsWithDelta(14.0, $a['lost_per_day'], 0.01);
        $this->assertSame(3, $a['items'][0]['days_out']);
        $this->assertEqualsWithDelta(42.0, $a['lost_period'], 0.01);
        $this->assertSame('Item X', $a['items'][0]['name']);
        $this->assertSame('availability', $this->row($r, $one)['top_action']);

        $b = $this->row($r, $two)['availability'];
        $this->assertSame(1, $b['out_a']);
        $this->assertSame(1, $b['unknown']);
        $this->assertSame(0, $b['valued']);
        $this->assertSame(0.0, $b['lost_per_day']);
        $this->assertSame('unknown', $b['items'][0]['state']);

        $c = $this->row($r, $three)['availability'];
        $this->assertSame(1, $c['valued']);
        $this->assertEqualsWithDelta(12.0, $c['items'][0]['price'], 0.001, 'list price, not the cost price');
        $this->assertEqualsWithDelta(2 * 12 * 0.7, $c['lost_per_day'], 0.01);
        $this->assertSame(7, $c['items'][0]['days_out']);
        $this->assertEqualsWithDelta(7 * 2 * 12 * 0.7, $c['lost_period'], 0.01);
        $this->assertSame('2026-10-07', $r['stock_as_of']);
        $this->assertStringNotContainsString('31337', json_encode($r));
    }

    public function test_peak_hours_need_a_single_active_register_and_a_repeating_pattern(): void
    {
        $shop = Shop::factory()->create(['name' => 'Busy']);
        $this->sale($shop, '2026-08-11 09:00:00', 10);       // creates the first till
        Pos::factory()->create(['shop_id' => $shop->id]);    // second register, idle
        // Wednesdays 2026-08-12 .. 09-30 (8 of them): 12 receipts at 12:00 on three of them, 2 on the rest.
        $peaks = ['2026-09-30', '2026-09-16', '2026-08-26'];
        foreach (['2026-08-12', '2026-08-19', '2026-08-26', '2026-09-02', '2026-09-09', '2026-09-16', '2026-09-23', '2026-09-30'] as $date) {
            for ($i = 0; $i < (in_array($date, $peaks, true) ? 12 : 2); $i++) {
                $this->sale($shop, sprintf('%s 12:%02d:00', $date, $i), 10);
            }
        }

        $row = $this->row($this->build([$shop], '2026-08-12', '2026-10-06'), $shop);

        $this->assertCount(1, $row['peaks']);
        $this->assertSame(3, $row['peaks'][0]['weekday']);
        $this->assertSame(12, $row['peaks'][0]['hour']);
        $this->assertSame(3, $row['peaks'][0]['days']);
        $this->assertContains('peak', $row['flags']);

        // A second active till in that hour means the queue was served by two registers: no signal.
        $second = Pos::query()->where('shop_id', $shop->id)->orderByDesc('id')->first();
        $ids = DB::table('receipts')->where('shop_id', $shop->id)->where('created_at', 'like', '2026-09-30 12:0%')->limit(2)->pluck('id');
        DB::table('receipts')->whereIn('id', $ids)->update(['pos_id' => $second->id]);
        $this->assertSame([], $this->row($this->build([$shop], '2026-08-12', '2026-10-06'), $shop)['peaks'], 'two tills served that day: it is no longer a peak day, so 2 days remain');

        // A shop with a single register is never checked.
        $solo = Shop::factory()->create();
        $this->sale($solo, '2026-09-30 12:00:00', 10);
        $this->assertSame([], $this->row($this->build([$solo], '2026-08-12', '2026-10-06'), $solo)['peaks']);
    }

    public function test_disabled_shops_and_the_warehouse_are_excluded(): void
    {
        $s = $this->chain();
        $disabled = Shop::factory()->create(['name' => 'Closed shop', 'is_disabled' => true]);
        $warehouse = Shop::factory()->create(['name' => 'Main warehouse']);
        $this->fill($disabled, 14, 500);
        $this->fill($warehouse, 14, 500);
        Warehouse::setShopId($warehouse->id);

        $r = $this->build([...$s, $disabled, $warehouse]);

        $this->assertSame(5, $r['kpi']['shops']);
        $this->assertSame(5, $r['benchmarks']['check']['shops'], 'their 500 receipts are not in the benchmark either');
        $this->assertEqualsWithDelta(130.0, $r['benchmarks']['check']['value'], 0.001);
        $json = json_encode($r);
        $this->assertStringNotContainsString('Closed shop', $json);
        $this->assertStringNotContainsString('Main warehouse', $json);
        $this->assertSame([], $this->build([$disabled, $warehouse]));
    }

    public function test_an_operator_sees_only_his_shops_but_the_benchmark_stays_chain_wide(): void
    {
        $s = $this->chain();
        $operator = User::factory()->operator()->create();
        $operator->shops()->sync([$s['A']->id]);

        $page = Livewire::actingAs($operator)->test(SalesGapsPage::class)
            ->set('shopIds', [$s['A']->id, $s['B']->id, $s['E']->id]) // B and E are not his
            ->set('dateFrom', '2026-09-30')->set('dateTo', '2026-10-06')
            ->call('generate')
            ->assertSee('Shop A')
            ->assertDontSee('Shop B')->assertDontSee('Shop C')->assertDontSee('Shop D')->assertDontSee('Shop E');

        $html = $page->html();
        // The percentile value is shown (chain P75 = 130), never other shops' names.
        $this->assertMatchesRegularExpression('/>\s*130\s*</', $html);

        $report = app(SalesGaps::class)->build(Carbon::parse('2026-09-30'), Carbon::parse('2026-10-06'), [$s['A']->id]);
        $this->assertCount(1, $report['shops']);
        $this->assertEqualsWithDelta(130.0, $report['benchmarks']['check']['value'], 0.001);
        foreach (['Shop B', 'Shop C', 'Shop D', 'Shop E'] as $name) {
            $this->assertStringNotContainsString($name, json_encode($report));
        }
    }

    public function test_generate_computes_nothing_before_it_is_pressed_and_every_press_recomputes(): void
    {
        $this->chain();
        $admin = User::factory()->create();

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });

        $component = Livewire::actingAs($admin)->test(SalesGapsPage::class)
            ->assertSee('No report yet')
            ->assertSee('Generate');

        $heavy = function () use (&$queries) {
            return collect($queries)->filter(fn ($sql) => preg_match('/from [`"]?(receipts|stocks|item_order_rules|receipt_items)[`"]?/i', $sql))->count();
        };
        $this->assertSame(0, $heavy(), 'nothing heavy runs before Generate');

        $component->set('dateFrom', '2026-09-30')->set('dateTo', '2026-10-06')->call('generate')->assertSee('Shops analysed');
        $this->assertGreaterThan(0, $heavy());
        $firstRun = $component->get('runKey');

        $before = $heavy();
        $component->call('generate');
        $this->assertNotSame($firstRun, $component->get('runKey'));
        $this->assertGreaterThan($before, $heavy(), 'a second press builds fresh data');
        $component->assertSee('Generate');
    }

    public function test_period_rules(): void
    {
        $this->chain();
        $admin = User::factory()->create();

        foreach ([['2026-10-05', '2026-10-06'], ['2026-09-30', '2026-10-07'], ['2026-06-01', '2026-10-06']] as [$from, $to]) {
            Livewire::actingAs($admin)->test(SalesGapsPage::class)
                ->set('dateFrom', $from)->set('dateTo', $to)->call('generate')
                ->assertHasErrors('dateTo')->assertSet('generated', false);
        }
    }

    public function test_output_has_no_cost_figures_or_cashier_names(): void
    {
        $s = $this->chain();
        $item = Item::factory()->create(['name' => 'Costly item']);
        $this->cost($item, 7919);
        $this->sale($s['A'], '2026-10-02 11:00:00', 100, ['cashier' => 'Zafar Kassirov'], 1, $item, 1);
        $admin = User::factory()->create();

        $report = $this->build($s);
        $this->assertStringNotContainsString('Zafar', json_encode($report));
        $this->assertStringNotContainsString('7919', json_encode($report));

        $html = Livewire::actingAs($admin)->test(SalesGapsPage::class)
            ->set('dateFrom', '2026-09-30')->set('dateTo', '2026-10-06')->call('generate')
            ->call('selectShop', $s['A']->id)->html();
        $this->assertStringNotContainsString('Zafar', $html);
        $this->assertStringNotContainsString('7919', $html);
        $this->assertStringNotContainsString('profit', strtolower(strip_tags($html)));
        $this->assertStringContainsString('How this is calculated', $html);
        $this->assertStringContainsString('What to do in', $html);
    }

    public function test_the_page_is_for_admin_and_operator_only(): void
    {
        $this->actingAs(User::factory()->create())->get(route('sales-gaps.index'))->assertOk();
        $this->actingAs(User::factory()->operator()->create())->get(route('sales-gaps.index'))->assertOk();
        $this->actingAs(User::factory()->monitor()->create())->get(route('sales-gaps.index'))->assertRedirect();
    }
}
