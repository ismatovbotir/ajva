<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\Shop;
use App\Models\Stock;
use App\Monitor\LayoutValidator;
use App\Monitor\MonitorData;
use App\Monitor\Presets;
use App\Monitor\WidgetCatalog;
use App\Services\SalesGaps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsCustomMonitors;
use Tests\TestCase;

/**
 * The Sales gaps widgets: they read the SAME service as the page (SalesGaps over the 28 whole days ending yesterday).
 * "Today" is 2026-10-07; thresholds are lowered through config so the fixtures stay small.
 */
class MonitorSalesGapsWidgetsTest extends TestCase
{
    use BuildsCustomMonitors;
    use RefreshDatabase;

    private const KEYS = ['gap_check_by_shop', 'gap_card_share_by_shop', 'gap_availability_lost', 'gap_availability_a_items'];

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        app()->setLocale('en');
        config(['sales_gaps.min_receipts' => 10, 'sales_gaps.card.min_chain_card_receipts' => 10]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function fill(Shop $shop, int $n, float $total, array $attrs = []): void
    {
        for ($i = 0; $i < $n; $i++) {
            $day = Carbon::parse('2026-09-30')->addDays($i % 7)->toDateString();
            $this->sale($shop, sprintf('%s 10:%02d:00', $day, $i % 60), $total, $attrs);
        }
    }

    /** @return array<string, Shop> five shops with checks 100 .. 140 */
    private function chain(): array
    {
        $shops = [];
        foreach (['A' => 100, 'B' => 110, 'C' => 120, 'D' => 130, 'E' => 140] as $name => $total) {
            $shops[$name] = Shop::factory()->create(['name' => "Shop {$name}"]);
            $this->fill($shops[$name], 14, $total);
        }

        return $shops;
    }

    private function build(array $keys, ?array $shopIds = null, bool $public = false): array
    {
        return MonitorData::build($this->scopeFor($this->customMonitor($keys), $shopIds, false, $public));
    }

    public function test_the_widgets_are_in_the_sales_group_and_follow_the_rules(): void
    {
        foreach (self::KEYS as $key) {
            $def = WidgetCatalog::find($key);
            $this->assertNotNull($def, $key);
            $this->assertSame('Sales', $def->group);
            $this->assertSame('sales_gaps', $def->dataset);
            $this->assertFalse($def->profit);
            $this->assertTrue($def->publicSafe);
            $this->assertNotNull($def->rule);
        }
        $this->assertSame('expensive', WidgetCatalog::find('gap_availability_a_items')->cost);
        $this->assertSame([], LayoutValidator::validate(Presets::layout('sales_growth'))['errors']);
    }

    public function test_check_gap_shows_only_flagged_shops_and_matches_the_service(): void
    {
        $this->chain();
        $r = $this->build(['gap_check_by_shop']);
        $p = $this->payload($r, 'gap_check_by_shop');

        // Benchmark P75 = 130: A (100) is 23.1 % below, B (110) 15.4 %, C (120) 7.7 % is under the 8 % flag.
        $this->assertSame(['Shop A', 'Shop B'], array_column($p['items'], 'label'));
        $this->assertSame('warn', $p['items'][0]['status']);
        $this->assertSame('23.1%', $p['items'][0]['cells'][0]);
        $this->assertStringStartsWith('≈', $p['items'][0]['cells'][2]);
        $this->assertStringContainsString('Benchmark 130', $p['footer']);
        $this->assertStringContainsString('P75 of 5 shops', $p['footer']);
        $this->assertStringContainsString('not a promise', $p['footer']);

        $report = app(SalesGaps::class)->build(Carbon::parse('2026-09-09'), Carbon::parse('2026-10-06'), Shop::query()->pluck('id')->all());
        $a = collect($report['shops'])->firstWhere('name', 'Shop A');
        $this->assertSame(number_format($a['check']['gap_pct'], 1).'%', $p['items'][0]['cells'][0], 'the widget never recomputes the gap');
    }

    public function test_check_gap_all_clear_and_below_the_minimum_sample(): void
    {
        foreach ([120, 120, 120] as $i => $total) {
            $this->fill(Shop::factory()->create(['name' => 'Same '.$i]), 14, $total);
        }
        $clear = $this->payload($this->build(['gap_check_by_shop']), 'gap_check_by_shop');
        $this->assertSame('ok', $clear['items'][0]['status']);
        $this->assertSame('No shop is below the chain benchmark.', $clear['items'][0]['label']);

    }

    public function test_a_shop_under_the_minimum_gets_no_rate(): void
    {
        $small = Shop::factory()->create(['name' => 'Small']);
        $this->fill($small, 4, 50);
        $none = $this->payload($this->build(['gap_check_by_shop']), 'gap_check_by_shop');
        $this->assertSame('empty', $none['state']);
        $this->assertStringContainsString('Not enough data', $none['footer']);
        $this->assertArrayNotHasKey('items', $none);
    }

    public function test_card_share_lists_shops_against_the_target_and_hides_when_conditions_fail(): void
    {
        $shops = [];
        foreach ([0, 2, 4, 6, 8] as $i => $cards) {
            $shops[$i] = Shop::factory()->create(['name' => 'Card '.$i]);
            $this->fill($shops[$i], 14 - $cards, 100);
            $this->fill($shops[$i], $cards, 200, ['aos' => ['card' => '111']]);
        }

        $p = $this->payload($this->build(['gap_card_share_by_shop']), 'gap_card_share_by_shop');
        $this->assertSame('Card 0', $p['items'][0]['label'], 'the biggest gap first');
        $this->assertSame('warn', $p['items'][0]['status']);
        $this->assertSame('0.0%', $p['items'][0]['cells'][0]);
        $this->assertSame('42.9%', $p['items'][0]['cells'][1], 'target = chain P75 of the shares (6/14)');
        $this->assertStringContainsString('correlation, not proof', $p['footer']);
        $top = collect($p['items'])->firstWhere('label', 'Card 4');
        $this->assertSame('ok', $top['status']);

        config(['sales_gaps.card.min_chain_card_receipts' => 1000]);
        \Illuminate\Support\Facades\Cache::flush();
        $hidden = $this->payload($this->build(['gap_card_share_by_shop']), 'gap_card_share_by_shop');
        $this->assertSame('empty', $hidden['state']);
        $this->assertStringContainsString('Not enough data', $hidden['footer']);
    }

    public function test_availability_widgets_use_the_estimate_and_label_it(): void
    {
        $one = Shop::factory()->create(['name' => 'Shop One']);
        $x = Item::factory()->create(['name' => 'Item X']);
        ItemOrderRule::factory()->create(['item_id' => $x->id, 'shop_id' => $one->id, 'min' => 5, 'max' => 20]);
        for ($d = Carbon::parse('2026-09-23'); $d->lte(Carbon::parse('2026-10-03')); $d->addDay()) {
            $this->sale($one, $d->toDateString().' 10:00:00', 20, [], 1, $x, 2);
        }
        foreach (['2026-10-04', '2026-10-05', '2026-10-06', '2026-10-07'] as $date) {
            Stock::factory()->create(['item_id' => $x->id, 'shop_id' => $one->id, 'qty' => 0, 'stock_date' => $date]);
        }

        $r = $this->build(['gap_availability_lost', 'gap_availability_a_items']);

        // 2 a day x realised price 10 x 0.7 = 14 a day.
        $kpi = $this->payload($r, 'gap_availability_lost');
        $this->assertSame('≈ 14', $kpi['value']);
        $this->assertSame('bad', $kpi['status']);
        $this->assertStringContainsString('1 class A items out in 1 shops', $kpi['note']);
        $this->assertStringContainsString('day-level estimate', $kpi['footer']);

        $table = $this->payload($r, 'gap_availability_a_items');
        $this->assertSame('Item X', $table['items'][0]['label']);
        $this->assertSame(['Shop One', '≈ 14', '3'], $table['items'][0]['cells']);
        $this->assertSame('bad', $table['items'][0]['status']);
        $this->assertStringContainsString('Stock as of last sync: 2026-10-07', $table['footer']);
    }

    public function test_an_unvalued_item_shows_not_enough_data_never_money(): void
    {
        $two = Shop::factory()->create(['name' => 'Shop Two']);
        $w = Item::factory()->create(['name' => 'Item W']);
        ItemOrderRule::factory()->create(['item_id' => $w->id, 'shop_id' => $two->id, 'min' => 5, 'max' => 20]);
        $this->sale($two, '2026-10-05 10:00:00', 20, [], 1, $w, 2);
        $this->sale($two, '2026-10-06 10:00:00', 20, [], 1, $w, 2);
        Stock::factory()->create(['item_id' => $w->id, 'shop_id' => $two->id, 'qty' => 0, 'stock_date' => '2026-10-07']);

        $r = $this->build(['gap_availability_lost', 'gap_availability_a_items']);

        $kpi = $this->payload($r, 'gap_availability_lost');
        $this->assertSame('—', $kpi['value']);
        $this->assertStringContainsString('Not enough data', $kpi['note']);
        $this->assertSame('Not enough data', $this->payload($r, 'gap_availability_a_items')['items'][0]['cells'][1]);
        $this->assertSame('warn', $this->payload($r, 'gap_availability_a_items')['items'][0]['status']);
    }

    public function test_no_class_a_item_out_is_an_all_clear(): void
    {
        $shop = Shop::factory()->create();
        $this->ruledItem($shop, 10);

        $r = $this->build(['gap_availability_lost', 'gap_availability_a_items']);

        $this->assertSame('0', $this->payload($r, 'gap_availability_lost')['value']);
        $this->assertSame('ok', $this->payload($r, 'gap_availability_lost')['status']);
        $this->assertSame('ok', $this->payload($r, 'gap_availability_a_items')['items'][0]['status']);
    }

    public function test_public_links_keep_the_widgets_and_carry_no_cost_or_cashier_names(): void
    {
        $shops = $this->chain();
        $item = Item::factory()->create(['name' => 'Costly item']);
        $this->cost($item, 7919);
        $this->sale($shops['A'], '2026-10-02 11:00:00', 100, ['cashier' => 'Zafar Kassirov'], 1, $item, 1);

        $r = $this->build(self::KEYS, null, true);

        foreach (self::KEYS as $key) {
            $this->assertNotNull($this->payload($r, $key), $key.' is public safe');
        }
        $json = json_encode($r);
        $this->assertStringNotContainsString('Zafar', $json);
        $this->assertStringNotContainsString('7919', $json);
    }

    public function test_a_monitor_with_fewer_shops_sees_only_those_shops_but_a_chain_wide_benchmark(): void
    {
        $shops = $this->chain();

        $r = MonitorData::build($this->scopeFor($this->customMonitor(['gap_check_by_shop']), [$shops['A']->id]));
        $p = $this->payload($r, 'gap_check_by_shop');

        $this->assertSame(['Shop A'], array_column($p['items'], 'label'));
        $this->assertStringContainsString('Benchmark 130', $p['footer']);
        $this->assertStringNotContainsString('Shop B', json_encode($p));
    }
}
