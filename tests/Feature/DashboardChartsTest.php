<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Group;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\ItemPrice;
use App\Models\Price;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardChartsTest extends TestCase
{
    use RefreshDatabase;

    private function seedKnownStock(): array
    {
        $shopA = Shop::factory()->create(['name' => 'Shop Alpha']);
        $shopB = Shop::factory()->create(['name' => 'Shop Beta']);

        $groupOne = Group::factory()->create(['name' => 'Group One']);
        $groupTwo = Group::factory()->create(['name' => 'Group Two']);

        $itemOne = Item::factory()->create(['group_id' => $groupOne->id]);
        $itemTwo = Item::factory()->create(['group_id' => $groupTwo->id]);
        $itemNoGroup = Item::factory()->create(['group_id' => null]);

        // Shop Alpha: 17 + 13 + 9 = 39
        Stock::factory()->create(['shop_id' => $shopA->id, 'item_id' => $itemOne->id, 'qty' => 17]);
        Stock::factory()->create(['shop_id' => $shopA->id, 'item_id' => $itemTwo->id, 'qty' => 13]);
        Stock::factory()->create(['shop_id' => $shopA->id, 'item_id' => $itemNoGroup->id, 'qty' => 9]);

        // Shop Beta: 8 + 6 + 4 = 18
        Stock::factory()->create(['shop_id' => $shopB->id, 'item_id' => $itemOne->id, 'qty' => 8]);
        Stock::factory()->create(['shop_id' => $shopB->id, 'item_id' => $itemTwo->id, 'qty' => 6]);
        Stock::factory()->create(['shop_id' => $shopB->id, 'item_id' => $itemNoGroup->id, 'qty' => 4]);

        return compact('shopA', 'shopB', 'groupOne', 'groupTwo', 'itemOne', 'itemTwo', 'itemNoGroup');
    }

    public function test_dashboard_aggregates_stock_by_shop_correctly(): void
    {
        $user = User::factory()->create();
        $this->seedKnownStock();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('shopRows', function (array $rows) {
                // Sorted descending by total: Shop Alpha (39) before Shop Beta (18).
                $this->assertSame('Shop Alpha', $rows[0]['name']);
                $this->assertEqualsWithDelta(39.0, $rows[0]['total'], 0.001);
                $this->assertSame('39', $rows[0]['label']);

                $this->assertSame('Shop Beta', $rows[1]['name']);
                $this->assertEqualsWithDelta(18.0, $rows[1]['total'], 0.001);
                $this->assertSame('18', $rows[1]['label']);

                return true;
            });
    }

    public function test_dashboard_aggregates_stock_by_group_and_rolls_up_ungrouped_items(): void
    {
        $user = User::factory()->create();
        $this->seedKnownStock();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('groupRows', function (array $rows) {
                // Group One: 17 + 8 = 25; Group Two: 13 + 6 = 19; No group: 9 + 4 = 13.
                $byName = collect($rows)->keyBy('name');

                $this->assertEqualsWithDelta(25.0, $byName['Group One']['total'], 0.001);
                $this->assertEqualsWithDelta(19.0, $byName['Group Two']['total'], 0.001);
                $this->assertEqualsWithDelta(13.0, $byName[__('No group')]['total'], 0.001);

                // Sorted descending by total.
                $this->assertSame('Group One', $rows[0]['name']);

                return true;
            });
    }

    public function test_dashboard_aggregates_stock_by_group_and_shop_combined(): void
    {
        $user = User::factory()->create();
        $this->seedKnownStock();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('groupShop', function (array $groupShop) {
                $rows = collect($groupShop['rows'])->keyBy('name');

                $groupOneSegments = collect($rows['Group One']['segments'])->keyBy('name');
                $this->assertEqualsWithDelta(17.0, $groupOneSegments['Shop Alpha']['value'], 0.001);
                $this->assertEqualsWithDelta(8.0, $groupOneSegments['Shop Beta']['value'], 0.001);

                $noGroupSegments = collect($rows[__('No group')]['segments'])->keyBy('name');
                $this->assertEqualsWithDelta(9.0, $noGroupSegments['Shop Alpha']['value'], 0.001);
                $this->assertEqualsWithDelta(4.0, $noGroupSegments['Shop Beta']['value'], 0.001);

                // Only 2 shops exist, well under the 7-color cap, so no "Other" fold-in.
                $this->assertCount(2, $groupShop['legend']);
                $this->assertSame('Shop Alpha', $groupShop['legend'][0]['name']);
                $this->assertSame('Shop Beta', $groupShop['legend'][1]['name']);

                return true;
            });
    }

    public function test_dashboard_donut_folds_shops_beyond_the_top_five(): void
    {
        $user = User::factory()->create();

        $shops = Shop::factory()->count(7)->sequence(
            ['name' => 'Shop 1'],
            ['name' => 'Shop 2'],
            ['name' => 'Shop 3'],
            ['name' => 'Shop 4'],
            ['name' => 'Shop 5'],
            ['name' => 'Shop 6'],
            ['name' => 'Shop 7'],
        )->create();

        $item = Item::factory()->create(['group_id' => null]);

        foreach ($shops as $i => $shop) {
            // Descending quantities: 70, 60, 50, 40, 30, 20, 10.
            Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => 70 - $i * 10]);
        }

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('donut', function (array $donut) {
                // Top 5 (70,60,50,40,30) + Other (20+10=30) = 6 segments total.
                $this->assertCount(6, $donut['segments']);
                $this->assertSame(__('Other'), $donut['segments'][5]['name']);
                $this->assertEqualsWithDelta(30.0, $donut['segments'][5]['value'], 0.001);

                return true;
            });
    }

    public function test_dashboard_renders_end_to_end_for_a_logged_in_user(): void
    {
        $user = User::factory()->create();
        $this->seedKnownStock();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSeeLivewire(Dashboard::class)
            ->assertSee(__('Stock by shop'))
            ->assertSee(__('Stock by group'))
            ->assertSee(__('Stock by group and shop'))
            ->assertSee('Shop Alpha')
            ->assertSee('Shop Beta')
            ->assertSee('Group One')
            ->assertSee('Group Two')
            ->assertSee(__('No group'));
    }

    public function test_guest_cannot_access_dashboard(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_dashboard_chart_1_and_2_default_to_bar_view_and_chart_3_defaults_to_table(): void
    {
        $user = User::factory()->create();
        $this->seedKnownStock();

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        // Chart 1 ("Stock by shop") and chart 2 ("Stock by group") both default
        // to the bar view; chart 3 ("Stock by group and shop") defaults to table.
        $this->assertSame(2, substr_count($html, "view: 'bar',"));
        $this->assertSame(1, substr_count($html, "view: 'table',"));
    }

    public function test_dashboard_group_donut_folds_groups_beyond_the_top_five(): void
    {
        $user = User::factory()->create();
        $shop = Shop::factory()->create();

        $groups = Group::factory()->count(7)->sequence(
            ['name' => 'Group 1'],
            ['name' => 'Group 2'],
            ['name' => 'Group 3'],
            ['name' => 'Group 4'],
            ['name' => 'Group 5'],
            ['name' => 'Group 6'],
            ['name' => 'Group 7'],
        )->create();

        foreach ($groups as $i => $group) {
            // Descending quantities: 70, 60, 50, 40, 30, 20, 10.
            $item = Item::factory()->create(['group_id' => $group->id]);
            Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => 70 - $i * 10]);
        }

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('donutGroup', function (array $donut) {
                // Top 5 (70,60,50,40,30) + Other (20+10=30) = 6 segments total.
                $this->assertCount(6, $donut['segments']);
                $this->assertSame(__('Other'), $donut['segments'][5]['name']);
                $this->assertEqualsWithDelta(30.0, $donut['segments'][5]['value'], 0.001);

                return true;
            });
    }

    /**
     * @return array{shop: Shop, itemStockout: Item, itemVeryLow: Item, itemLow: Item, itemFine: Item, itemZeroMin: Item}
     */
    private function seedReorderExceptions(): array
    {
        $shop = Shop::factory()->create(['name' => 'Shop X']);

        // qty 0, min 10 -> ratio 0 -> critical (outright stockout).
        $itemStockout = Item::factory()->create(['name' => 'Item Stockout']);
        // qty 3, min 10 -> ratio 0.3 -> critical (very low ratio).
        $itemVeryLow = Item::factory()->create(['name' => 'Item Very Low']);
        // qty 8, min 10 -> ratio 0.8 -> warning.
        $itemLow = Item::factory()->create(['name' => 'Item Low']);
        // qty 12, min 10 -> above minimum, not an exception at all.
        $itemFine = Item::factory()->create(['name' => 'Item Fine']);
        // qty 0, min 0 -> ratio forced to 0 -> critical.
        $itemZeroMin = Item::factory()->create(['name' => 'Item Zero Min']);

        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemStockout->id, 'qty' => 0]);
        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemVeryLow->id, 'qty' => 3]);
        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemLow->id, 'qty' => 8]);
        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemFine->id, 'qty' => 12]);
        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemZeroMin->id, 'qty' => 0]);

        ItemOrderRule::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemStockout->id, 'min' => 10, 'max' => 20]);
        ItemOrderRule::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemVeryLow->id, 'min' => 10, 'max' => 20]);
        ItemOrderRule::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemLow->id, 'min' => 10, 'max' => 20]);
        ItemOrderRule::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemFine->id, 'min' => 10, 'max' => 20]);
        ItemOrderRule::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemZeroMin->id, 'min' => 0, 'max' => 0]);

        return compact('shop', 'itemStockout', 'itemVeryLow', 'itemLow', 'itemFine', 'itemZeroMin');
    }

    public function test_dashboard_reorder_exceptions_are_ranked_by_severity_most_urgent_first(): void
    {
        $user = User::factory()->create();
        $this->seedReorderExceptions();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('exceptions', function (array $exceptions) {
                $this->assertSame(4, $exceptions['total']);
                $this->assertCount(4, $exceptions['rows']);
                $this->assertSame(0, $exceptions['hidden']);

                $rows = $exceptions['rows'];

                // Ratio 0 rows first (tie-broken by largest min-qty gap first),
                // then ratio 0.3 (critical), then ratio 0.8 (warning) last.
                $this->assertSame('Item Stockout', $rows[0]['item_name']);
                $this->assertSame('critical', $rows[0]['severity']);

                $this->assertSame('Item Zero Min', $rows[1]['item_name']);
                $this->assertSame('critical', $rows[1]['severity']);

                $this->assertSame('Item Very Low', $rows[2]['item_name']);
                $this->assertSame('critical', $rows[2]['severity']);

                $this->assertSame('Item Low', $rows[3]['item_name']);
                $this->assertSame('warning', $rows[3]['severity']);

                return true;
            });
    }

    public function test_dashboard_reorder_exceptions_are_capped_with_a_hidden_count(): void
    {
        $user = User::factory()->create();
        $shop = Shop::factory()->create();

        for ($i = 0; $i < 25; $i++) {
            $item = Item::factory()->create();
            Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => 1]);
            ItemOrderRule::factory()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'min' => 10, 'max' => 20]);
        }

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('exceptions', function (array $exceptions) {
                $this->assertSame(25, $exceptions['total']);
                $this->assertCount(20, $exceptions['rows']);
                $this->assertSame(5, $exceptions['hidden']);

                return true;
            });
    }

    public function test_dashboard_rule_coverage_percentage_is_computed_correctly(): void
    {
        $user = User::factory()->create();
        $shop = Shop::factory()->create();

        $itemWithRule1 = Item::factory()->create();
        $itemWithRule2 = Item::factory()->create();
        $itemWithoutRule1 = Item::factory()->create();
        $itemWithoutRule2 = Item::factory()->create();

        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemWithRule1->id]);
        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemWithRule2->id]);
        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemWithoutRule1->id]);
        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemWithoutRule2->id]);

        ItemOrderRule::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemWithRule1->id]);
        ItemOrderRule::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemWithRule2->id]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('coverage', function (array $coverage) {
                $this->assertSame(4, $coverage['total']);
                $this->assertSame(2, $coverage['covered']);
                $this->assertEqualsWithDelta(50.0, $coverage['percent'], 0.01);

                return true;
            });
    }

    public function test_dashboard_rule_coverage_and_exceptions_are_both_empty_when_no_rules_exist(): void
    {
        $user = User::factory()->create();
        $shop = Shop::factory()->create();
        $item = Item::factory()->create();
        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $item->id]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('coverage', function (array $coverage) {
                $this->assertSame(1, $coverage['total']);
                $this->assertSame(0, $coverage['covered']);
                $this->assertSame(0.0, $coverage['percent']);

                return true;
            })
            ->assertViewHas('exceptions', function (array $exceptions) {
                $this->assertSame(0, $exceptions['total']);
                $this->assertEmpty($exceptions['rows']);

                return true;
            });
    }

    /**
     * @return array{group: Group, costPrice: Price, sellPrice: Price, itemGoodMargin: Item, itemBadMargin: Item, itemCostOnly: Item, itemSellOnly: Item, itemNoPrices: Item}
     */
    private function seedMargins(): array
    {
        $group = Group::factory()->create(['name' => 'Group M']);

        $costPrice = Price::factory()->create(['id' => config('inventory.cost_price_id'), 'name' => 'Cost', 'is_sell' => false]);
        $sellPrice = Price::factory()->create(['name' => 'Sell', 'is_sell' => true]);

        $itemGoodMargin = Item::factory()->create(['name' => 'Item Good Margin', 'group_id' => $group->id]);
        $itemBadMargin = Item::factory()->create(['name' => 'Item Bad Margin', 'group_id' => $group->id]);
        $itemCostOnly = Item::factory()->create(['name' => 'Item Cost Only']);
        $itemSellOnly = Item::factory()->create(['name' => 'Item Sell Only']);
        $itemNoPrices = Item::factory()->create(['name' => 'Item No Prices']);

        // Cost 100, sell 150 -> margin 50, margin % 50.
        ItemPrice::factory()->create(['item_id' => $itemGoodMargin->id, 'price_id' => $costPrice->id, 'value' => 100]);
        ItemPrice::factory()->create(['item_id' => $itemGoodMargin->id, 'price_id' => $sellPrice->id, 'value' => 150]);

        // Cost 100, sell 90 -> margin -10, margin % -10 (sold at a loss).
        ItemPrice::factory()->create(['item_id' => $itemBadMargin->id, 'price_id' => $costPrice->id, 'value' => 100]);
        ItemPrice::factory()->create(['item_id' => $itemBadMargin->id, 'price_id' => $sellPrice->id, 'value' => 90]);

        // Cost price only — missing sell.
        ItemPrice::factory()->create(['item_id' => $itemCostOnly->id, 'price_id' => $costPrice->id, 'value' => 40]);

        // Sell price only — missing cost.
        ItemPrice::factory()->create(['item_id' => $itemSellOnly->id, 'price_id' => $sellPrice->id, 'value' => 60]);

        // $itemNoPrices has no item_prices rows at all — missing both.

        return compact('group', 'costPrice', 'sellPrice', 'itemGoodMargin', 'itemBadMargin', 'itemCostOnly', 'itemSellOnly', 'itemNoPrices');
    }

    public function test_dashboard_computes_item_margins_and_missing_price_counts(): void
    {
        $user = User::factory()->create();
        $this->seedMargins();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('margins', function (array $margins) {
                $this->assertSame(2, $margins['total_with_margin']);
                $this->assertSame(1, $margins['missing_cost']); // Item Sell Only
                $this->assertSame(1, $margins['missing_sell']); // Item Cost Only
                $this->assertSame(1, $margins['missing_both']); // Item No Prices

                $best = collect($margins['best'])->keyBy('item_name');
                $worst = collect($margins['worst'])->keyBy('item_name');

                $this->assertEqualsWithDelta(50.0, $best['Item Good Margin']['margin_pct'], 0.01);
                $this->assertSame('Group M', $best['Item Good Margin']['group_name']);
                $this->assertEqualsWithDelta(-10.0, $worst['Item Bad Margin']['margin_pct'], 0.01);
                $this->assertSame('Group M', $worst['Item Bad Margin']['group_name']);

                // Best list is sorted descending by margin %, worst ascending.
                $this->assertSame('Item Good Margin', $margins['best'][0]['item_name']);
                $this->assertSame('Item Bad Margin', $margins['worst'][0]['item_name']);

                return true;
            });
    }

    public function test_dashboard_lists_every_selling_price_below_cost(): void
    {
        $user = User::factory()->create();
        $cost = Price::factory()->create(['id' => config('inventory.cost_price_id'), 'name' => 'Cost', 'is_sell' => false]);
        $retail = Price::factory()->create(['name' => 'Retail', 'is_sell' => true]);
        $wholesale = Price::factory()->create(['name' => 'Wholesale', 'is_sell' => true]);

        $loss = Item::factory()->create(['name' => 'Item Loss']);       // retail 80 < cost 100, wholesale 90 < cost 100
        $fine = Item::factory()->create(['name' => 'Item Fine']);       // retail 150 >= cost 100
        $unset = Item::factory()->create(['name' => 'Item Unset']);     // price type present but 0 = not set

        foreach ([[$loss, $cost, 100], [$loss, $retail, 80], [$loss, $wholesale, 90],
            [$fine, $cost, 100], [$fine, $retail, 150],
            [$unset, $cost, 100], [$unset, $retail, 0]] as [$item, $price, $value]) {
            ItemPrice::factory()->create(['item_id' => $item->id, 'price_id' => $price->id, 'value' => $value]);
        }

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('belowCost', function (array $below) {
                $this->assertSame(2, $below['count']); // Item Loss, once per price type under cost
                $this->assertSame(['Item Loss', 'Item Loss'], array_column($below['rows'], 'item_name'));
                $this->assertSame('Retail', $below['rows'][0]['price_name']);   // deepest below cost first (-20%)
                $this->assertEqualsWithDelta(-20.0, $below['rows'][0]['pct'], 0.01);
                $this->assertSame('Wholesale', $below['rows'][1]['price_name']); // -10%

                return true;
            });
    }

    public function test_dashboard_excludes_zero_cost_items_from_margin_ranking_but_not_from_completeness(): void
    {
        $user = User::factory()->create();
        $costPrice = Price::factory()->create(['id' => config('inventory.cost_price_id'), 'is_sell' => false]);
        $sellPrice = Price::factory()->create(['is_sell' => true]);
        $item = Item::factory()->create(['name' => 'Item Zero Cost']);

        ItemPrice::factory()->create(['item_id' => $item->id, 'price_id' => $costPrice->id, 'value' => 0]);
        ItemPrice::factory()->create(['item_id' => $item->id, 'price_id' => $sellPrice->id, 'value' => 50]);

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('margins', function (array $margins) {
                $this->assertSame(0, $margins['total_with_margin']);
                $this->assertSame(0, $margins['missing_cost']);
                $this->assertSame(0, $margins['missing_sell']);
                $this->assertSame(0, $margins['missing_both']);
                $this->assertEmpty($margins['best']);
                $this->assertEmpty($margins['worst']);

                return true;
            });
    }

    public function test_dashboard_renders_reorder_risk_and_margin_sections_end_to_end(): void
    {
        $user = User::factory()->create();
        $this->seedReorderExceptions();
        $this->seedMargins();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSee(__('Reorder risk'))
            ->assertSee(__('Reorder-rule coverage'))
            ->assertSee(__('Margin by item'))
            ->assertSee('Item Stockout')
            ->assertSee('Item Good Margin');
    }
}
