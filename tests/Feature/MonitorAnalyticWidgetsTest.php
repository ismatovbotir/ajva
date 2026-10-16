<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Item;
use App\Models\Receipt;
use App\Models\Shop;
use App\Models\Stock;
use App\Monitor\Grid;
use App\Monitor\MonitorData;
use App\Monitor\WidgetCatalog;
use App\Support\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsCustomMonitors;
use Tests\TestCase;

/** The analytic pages (assortment, stock without sales, warehouse out of stock, customers) as monitor widgets, with real data. */
class MonitorAnalyticWidgetsTest extends TestCase
{
    use BuildsCustomMonitors;
    use RefreshDatabase;

    private Shop $a;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 15:00:00');
        $this->a = Shop::factory()->create(['name' => 'Alpha']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<int, string>  $keys */
    private function build(array $keys, bool $public = false): array
    {
        $layout = [];
        foreach ($keys as $key) {
            $layout = Grid::add($layout, WidgetCatalog::find($key), (string) Str::uuid());
        }
        $monitor = $this->customMonitor([]);

        return MonitorData::build($this->scopeFor($monitor, [$this->a->id], false, $public), $layout);
    }

    private function stock(Item $item, float $qty): void
    {
        Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $this->a->id, 'qty' => $qty, 'stock_date' => '2026-10-06']);
    }

    public function test_the_new_analytics_have_widgets_in_their_categories(): void
    {
        $groups = collect(WidgetCatalog::all())->mapWithKeys(fn ($d) => [$d->key => $d->group]);

        foreach (['abc_mix', 'abc_xyz_matrix', 'a_items_out', 'unsold_items', 'unsold_by_group'] as $key) {
            $this->assertSame('Assortment', $groups[$key], $key);
        }
        $this->assertSame('Inventory', $groups['wh_out_of_stock']);
        $this->assertSame('Receipts', $groups['customer_share']);
        foreach (['abc_mix', 'abc_xyz_matrix', 'a_items_out', 'unsold_items', 'unsold_by_group', 'wh_out_of_stock', 'customer_share'] as $key) {
            $def = WidgetCatalog::find($key);
            $this->assertFalse($def->profit, $key.' carries no cost figures');
            $this->assertTrue($def->publicSafe, $key);
        }
    }

    public function test_unsold_widgets_count_stock_without_sales(): void
    {
        $group = Group::factory()->create(['name' => 'Snacks']);
        $sold = Item::factory()->create(['group_id' => $group->id]);
        $dead = Item::factory()->create(['group_id' => $group->id, 'name' => 'Dead one']);
        $this->stock($sold, 5);
        $this->stock($dead, 40);
        $this->sale($this->a, '2026-09-20 10:00:00', 100, [], 1, $sold, 1);

        $r = $this->build(['unsold_items', 'unsold_by_group']);

        $w = $this->payload($r, 'unsold_items');
        $this->assertSame('1', $w['value']);
        $this->assertSame('warn', $w['status'], '50 % of the stocked items unsold is amber');
        $g = $this->payload($r, 'unsold_by_group');
        $this->assertSame('Snacks', $g['items'][0]['label']);
        $this->assertSame(['1', '2', '50%'], $g['items'][0]['cells']);
    }

    public function test_assortment_widgets_show_class_a_items_out_of_stock(): void
    {
        $top = Item::factory()->create(['name' => 'Top seller']);
        $this->stock($top, 0);
        for ($w = 0; $w < 12; $w++) {
            $this->sale($this->a, Carbon::parse('2026-07-15')->addDays(7 * $w + 2)->format('Y-m-d').' 10:00:00', 1000, [], 1, $top, 10);
        }

        $r = $this->build(['a_items_out', 'abc_mix', 'abc_xyz_matrix']);

        $out = $this->payload($r, 'a_items_out');
        $this->assertSame(['1', 'bad'], [$out['value'], $out['status']]);
        $mix = $this->payload($r, 'abc_mix');
        $this->assertEqualsWithDelta(100.0, $mix['parts'][0]['value'], 0.001, 'the single item is the whole class A');
        $matrix = $this->payload($r, 'abc_xyz_matrix');
        $this->assertSame('1 · 100%', $matrix['items'][0]['cells'][0], 'A and X: steady weekly sales');
    }

    public function test_the_warehouse_widget_explains_a_missing_warehouse_and_counts_when_configured(): void
    {
        $r = $this->build(['wh_out_of_stock']);
        $this->assertSame('empty', $this->payload($r, 'wh_out_of_stock')['state']);

        $wh = Shop::factory()->create(['name' => 'Main']);
        Warehouse::setShopId($wh->id);
        $item = Item::factory()->create();
        Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $wh->id, 'qty' => 5, 'stock_date' => '2026-10-05']);
        Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $wh->id, 'qty' => 0, 'stock_date' => '2026-10-06']);
        Stock::factory()->create(['item_id' => Item::factory()->create()->id, 'shop_id' => $wh->id, 'qty' => 9, 'stock_date' => '2026-10-06']);

        $w = $this->payload($this->build(['wh_out_of_stock']), 'wh_out_of_stock');
        $this->assertSame('ok', $w['state']);
        $this->assertSame('1', $w['value']);
    }

    public function test_customer_share_counts_todays_sale_receipts_with_a_customer(): void
    {
        $this->sale($this->a, '2026-10-07 09:00:00', 100, ['aos' => ['card' => '1234']]);
        $this->sale($this->a, '2026-10-07 10:00:00', 100);
        $this->sale($this->a, '2026-10-07 11:00:00', 100, ['sell' => false, 'aos' => ['card' => '1']]);   // a refund never counts
        $this->assertSame(3, Receipt::count());

        $w = $this->payload($this->build(['customer_share']), 'customer_share');

        $this->assertSame('50.0%', $w['value']);
    }

    public function test_public_links_get_the_new_widgets_without_any_cost_figure(): void
    {
        $item = Item::factory()->create();
        $this->stock($item, 10);
        $this->cost($item, 7);

        $r = $this->build(['unsold_items', 'a_items_out', 'wh_out_of_stock', 'customer_share', 'abc_mix', 'unsold_by_group', 'abc_xyz_matrix'], true);

        $this->assertCount(7, $r['widgets']);
    }
}
