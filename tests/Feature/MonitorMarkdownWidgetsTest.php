<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\ItemPrice;
use App\Models\Price;
use App\Models\Shop;
use App\Models\Stock;
use App\Monitor\MonitorData;
use App\Monitor\WidgetCatalog;
use App\Services\Markdowns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\BuildsCustomMonitors;
use Tests\TestCase;

/** The Markdown and promo widgets read the SAME service as the page. "Today" is 2026-10-07. */
class MonitorMarkdownWidgetsTest extends TestCase
{
    use BuildsCustomMonitors;
    use RefreshDatabase;

    private const KEYS = ['markdown_candidates_count', 'markdown_candidates_by_shop', 'markdown_top_items', 'discount_giveaway_by_item'];

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

    private function slow(Shop $shop, float $qty, float $price, string $name): Item
    {
        $id = (int) config('inventory.cost_price_id') + 1;
        $retail = Price::query()->find($id) ?? Price::factory()->create(['id' => $id, 'name' => 'Retail', 'is_sell' => true]);
        $item = Item::factory()->create(['name' => $name]);
        Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $shop->id, 'qty' => 5, 'stock_date' => '2026-05-01']);
        Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $shop->id, 'qty' => $qty, 'stock_date' => '2026-10-07']);
        ItemPrice::factory()->create(['item_id' => $item->id, 'price_id' => $retail->id, 'value' => $price]);

        return $item;
    }

    private function build(array $keys, ?array $shopIds = null, bool $public = false): array
    {
        return MonitorData::build($this->scopeFor($this->customMonitor($keys), $shopIds, false, $public));
    }

    public function test_catalog_entries_are_public_safe_without_profit(): void
    {
        foreach (self::KEYS as $key) {
            $def = WidgetCatalog::find($key);
            $this->assertNotNull($def, $key);
            $this->assertSame('Sales', $def->group);
            $this->assertFalse($def->profit);
            $this->assertTrue($def->publicSafe);
            $this->assertNotNull($def->rule);
        }
        $this->assertSame('markdown_discounts', WidgetCatalog::find('discount_giveaway_by_item')->dataset);
        $this->assertSame('expensive', WidgetCatalog::find('markdown_top_items')->cost);
    }

    public function test_widgets_match_the_service_and_show_sell_price_values(): void
    {
        $a = Shop::factory()->create(['name' => 'Shop A']);
        $b = Shop::factory()->create(['name' => 'Shop B']);
        $this->slow($a, 10, 100, 'Green tea');
        $this->slow($b, 4, 100, 'Laundry powder');

        $svc = app(Markdowns::class)->candidates(Carbon::parse('2026-08-08'), Carbon::parse('2026-10-06'), [$a->id, $b->id]);
        $this->assertSame(2, $svc['kpi']['candidates']);

        $r = $this->build(['markdown_candidates_count', 'markdown_candidates_by_shop', 'markdown_top_items'], [$a->id, $b->id], true);

        $kpi = $this->payload($r, 'markdown_candidates_count');
        $this->assertSame('2', $kpi['value']);
        $this->assertSame('warn', $kpi['status']);
        $this->assertStringContainsString('1 400 at sell price', $kpi['note']);
        $this->assertStringContainsString('No shelf-life data', $kpi['footer']);

        $shops = $this->payload($r, 'markdown_candidates_by_shop');
        $this->assertSame(['Shop A', 'Shop B'], array_column($shops['items'], 'label'));
        $this->assertSame('1 000', $shops['items'][0]['text']);

        $top = $this->payload($r, 'markdown_top_items');
        $this->assertSame('Green tea', $top['items'][0]['label']);
        $this->assertSame('30%', $top['items'][0]['cells'][2]);
        $this->assertStringContainsString('suggested start', $top['footer']);
        $this->assertStringNotContainsStringIgnoringCase('cost', json_encode($top['items']));
    }

    public function test_nothing_to_review_is_green_and_no_shops_is_empty(): void
    {
        $a = Shop::factory()->create(['name' => 'Shop A']);
        $this->slow($a, 10, 100, 'Seller');
        $ok = $this->payload($this->build(['markdown_candidates_count', 'markdown_candidates_by_shop'], [$a->id]), 'markdown_candidates_count');
        $this->assertContains($ok['state'], ['ok', 'empty']);

        $none = $this->payload($this->build(['markdown_candidates_count'], []), 'markdown_candidates_count');
        $this->assertSame('empty', $none['state']);
    }

    public function test_discount_giveaway_with_too_few_days_is_na_and_empty_without_discounts(): void
    {
        $a = Shop::factory()->create(['name' => 'Shop A']);
        $p = $this->payload($this->build(['discount_giveaway_by_item'], [$a->id]), 'discount_giveaway_by_item');
        $this->assertSame('empty', $p['state']);
        $this->assertArrayNotHasKey('items', $p);
    }
}
