<?php

namespace Tests\Feature;

use App\Livewire\UnsoldStock\Index as UnsoldPage;
use App\Mcp\Tools\UnsoldStockReport;
use App\Models\Group;
use App\Models\Item;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use App\Services\UnsoldStock;
use App\Support\LocalAdvisor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\BuildsCustomMonitors;
use Tests\TestCase;

/** Stock without sales against hand-made fixtures. Period 2026-08-08 .. 2026-10-06 (60 days), "today" is 2026-10-07. */
class UnsoldStockTest extends TestCase
{
    use BuildsCustomMonitors;
    use RefreshDatabase;

    private Shop $a;

    private Shop $b;

    private Item $sold;

    private Item $dead;

    private Item $splitItem;

    private Item $outOfStock;

    private Item $oldSale;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->a = Shop::factory()->create(['name' => 'Alpha']);
        $this->b = Shop::factory()->create(['name' => 'Beta']);
        $group = Group::factory()->create(['name' => 'Snacks']);
        $this->sold = Item::factory()->create(['name' => 'Sold', 'group_id' => $group->id]);
        $this->dead = Item::factory()->create(['name' => 'Dead', 'group_id' => $group->id]);
        $this->splitItem = Item::factory()->create(['name' => 'Split']);
        $this->outOfStock = Item::factory()->create(['name' => 'Empty shelf']);
        $this->oldSale = Item::factory()->create(['name' => 'Old sale']);

        $this->stock($this->sold, $this->a, 10);
        $this->stock($this->dead, $this->a, 30);
        $this->stock($this->dead, $this->b, 5);
        $this->stock($this->splitItem, $this->a, 8);   // sells in Alpha in the period
        $this->stock($this->splitItem, $this->b, 12);  // never in Beta
        $this->stock($this->outOfStock, $this->a, 0);  // no stock = never listed
        $this->stock($this->oldSale, $this->b, 7);

        $this->sale($this->a, '2026-09-20 10:00:00', 100, [], 1, $this->sold, 2);
        $this->sale($this->a, '2026-09-21 10:00:00', 100, [], 1, $this->splitItem, 1);
        $this->sale($this->b, '2026-06-01 10:00:00', 100, [], 1, $this->oldSale, 1);   // before the period
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stock(Item $item, Shop $shop, float $qty): void
    {
        Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $shop->id, 'qty' => $qty, 'stock_date' => now()->toDateString()]);
    }

    private function build(?array $shops = null, bool $profit = false, string $from = '2026-08-08', string $to = '2026-10-06'): array
    {
        return app(UnsoldStock::class)->build(Carbon::parse($from), Carbon::parse($to), $shops ?? [$this->a->id, $this->b->id], $profit);
    }

    public function test_an_item_is_unsold_when_no_selected_shop_sold_it_in_the_period(): void
    {
        $r = $this->build();

        $names = collect($r['items'])->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Dead', 'Old sale'], $names, 'sold items, items with no stock and items that sold elsewhere are not listed');
        $dead = collect($r['items'])->firstWhere('name', 'Dead');
        $this->assertSame([35.0, 2], [$dead['on_hand'], $dead['shops']], 'units are summed over the shops');
        $this->assertSame('Snacks', $dead['group']);
        $this->assertSame(4, $r['kpi']['stocked_items'], 'Sold, Dead, Split and Old sale have stock; the empty shelf does not count');
        $this->assertSame(2, $r['kpi']['unsold_items']);
        $this->assertEqualsWithDelta(50.0, $r['kpi']['unsold_share'], 0.001);
        $this->assertSame(42.0, $r['kpi']['unsold_units']);
        $this->assertSame(60, $r['window']['days']);
        $this->assertFalse($r['window']['short']);
    }

    public function test_the_shop_view_lists_stock_in_a_shop_that_did_not_sell_the_item_there(): void
    {
        $r = $this->build();
        $pairs = collect($r['pairs'])->map(fn ($p) => $p['name'].'@'.$p['shop'].'='.$p['on_hand'])->sort()->values()->all();

        $this->assertSame(['Dead@Alpha=30', 'Dead@Beta=5', 'Old sale@Beta=7', 'Split@Beta=12'], $pairs, 'Split sells in Alpha but not in Beta: a transfer candidate');
        $this->assertSame(4, $r['kpi']['unsold_pairs']);
    }

    public function test_the_shop_selection_limits_what_counts_as_a_sale_and_as_stock(): void
    {
        $onlyB = $this->build([$this->b->id]);

        // In Beta alone Split never sold (the Alpha sale does not count) and Alpha's stock is not counted.
        $this->assertEqualsCanonicalizing(['Dead', 'Old sale', 'Split'], collect($onlyB['items'])->pluck('name')->all());
        $this->assertSame(5.0, collect($onlyB['items'])->firstWhere('name', 'Dead')['on_hand']);
        $this->assertSame(0, $this->build([])['kpi']['stocked_items']);
    }

    public function test_refunds_cancelled_receipts_and_storno_lines_do_not_count_as_sales(): void
    {
        $this->sale($this->a, '2026-09-25 10:00:00', 500, ['sell' => false], 1, $this->dead, 3);
        $this->sale($this->a, '2026-09-25 11:00:00', 500, ['active' => false], 1, $this->dead, 3);
        $receipt = $this->sale($this->a, '2026-09-25 12:00:00', 0, [], 0);
        \App\Models\ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $this->dead->id, 'qty' => 9, 'total' => 900, 'storno' => true]);

        $this->assertContains('Dead', collect($this->build()['items'])->pluck('name')->all());

        // A real sale takes it off the list.
        $this->sale($this->b, '2026-09-26 10:00:00', 100, [], 1, $this->dead, 1);
        $this->assertNotContains('Dead', collect($this->build()['items'])->pluck('name')->all());
    }

    public function test_only_the_current_stock_snapshot_counts_never_the_history(): void
    {
        Stock::factory()->create(['item_id' => $this->dead->id, 'shop_id' => $this->a->id, 'qty' => 999, 'stock_date' => '2026-09-01']);   // older snapshot of the same pair

        $dead = collect($this->build()['items'])->firstWhere('name', 'Dead');
        $this->assertSame(35.0, $dead['on_hand']);
    }

    public function test_last_sale_is_read_only_for_the_asked_items_and_never_sold_is_null(): void
    {
        $this->sale($this->a, '2026-07-04 10:00:00', 100, [], 1, $this->dead, 1);   // sold before the period

        $svc = app(UnsoldStock::class);
        $perItem = $svc->lastSales([$this->dead->id, $this->oldSale->id, $this->splitItem->id], [$this->a->id, $this->b->id], Carbon::parse('2026-10-06'), false);

        $this->assertSame('2026-07-04', $perItem[(string) $this->dead->id]);
        $this->assertSame('2026-06-01', $perItem[(string) $this->oldSale->id]);

        $perShop = $svc->lastSales([$this->splitItem->id, $this->dead->id], [$this->a->id, $this->b->id], Carbon::parse('2026-10-06'), true);
        $this->assertArrayHasKey($this->splitItem->id.':'.$this->a->id, $perShop);
        $this->assertArrayNotHasKey($this->splitItem->id.':'.$this->b->id, $perShop, 'never sold in Beta');
        $this->assertSame([], $svc->lastSales([], [$this->a->id], Carbon::parse('2026-10-06'), false));
    }

    public function test_cost_figures_only_exist_with_profit_and_missing_cost_is_not_zero(): void
    {
        $this->cost($this->dead, 12);   // Old sale has no cost price

        $without = $this->build();
        $this->assertNull($without['kpi']['value']);
        $this->assertNull(collect($without['items'])->firstWhere('name', 'Dead')['value']);

        $with = $this->build(null, true);
        $this->assertSame(35 * 12.0, $with['kpi']['value']['unsold']);
        $this->assertSame(1, $with['kpi']['value']['without_cost']);
        $this->assertSame(420.0, collect($with['items'])->firstWhere('name', 'Dead')['value']);
        $this->assertNull(collect($with['items'])->firstWhere('name', 'Old sale')['value']);
    }

    public function test_groups_show_where_the_unsold_items_concentrate(): void
    {
        $g = $this->build()['groups'];

        $this->assertSame('Snacks', $g[0]['group'], 'a named group comes before the items without a group on a tie');
        $this->assertSame([1, 2], [$g[0]['unsold'], $g[0]['stocked']], 'Snacks: Dead unsold of Sold + Dead in stock');
        $this->assertEqualsWithDelta(50.0, $g[0]['share'], 0.001);
        $this->assertSame('', $g[1]['group'], 'no group: Old sale unsold, Split sold');
    }

    public function test_a_stale_snapshot_and_a_short_period_are_flagged(): void
    {
        Stock::query()->update(['stock_date' => '2026-10-01']);
        $r = $this->build(null, false, '2026-09-20', '2026-10-06');

        $this->assertTrue($r['kpi']['stock_stale']);
        $this->assertSame('2026-10-01', $r['kpi']['stock_as_of']);
        $this->assertTrue($r['window']['short']);
    }

    public function test_the_conclusions_name_the_action(): void
    {
        $notes = collect(LocalAdvisor::notes('unsold_stock', ['report' => $this->build()]));

        $this->assertGreaterThanOrEqual(2, $notes->count());
        $this->assertSame('warn', $notes->first()['level'], '50 % of the stocked items unsold is an action');
        $this->assertTrue($notes->contains(fn ($n) => str_contains($n['text'], '60')), 'the period is named');
        $this->assertSame([], LocalAdvisor::notes('unsold_stock', ['report' => null]));
    }

    // ---------------------------------------------------------------- page and tool

    public function test_the_page_calculates_nothing_until_generate_and_keeps_the_button(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get(route('unsold-stock.index'))->assertOk()->assertSee(__('Stock without sales'))->assertDontSee('Dead');

        Livewire::actingAs($admin)->test(UnsoldPage::class)
            ->assertSet('dateTo', '2026-10-06')->assertSet('dateFrom', '2026-08-08')
            ->assertSeeHtml('data-generate')->assertDontSee('Dead')
            ->call('generate')->assertSet('generated', true)
            ->assertSee('Dead')->assertSee('Old sale')->assertDontSee('Empty shelf')
            ->assertSeeHtml('data-generate')
            ->set('mode', 'shop')->assertSee('Beta')->assertSee('Split')
            ->set('search', 'dead')->assertDontSee('Split');
    }

    public function test_the_page_validates_the_period_and_shows_the_last_sale_of_the_visible_rows(): void
    {
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(UnsoldPage::class)
            ->set('dateFrom', '2026-01-01')->set('dateTo', '2026-10-06')->call('generate')->assertHasErrors('dateTo');
        Livewire::actingAs($admin)->test(UnsoldPage::class)
            ->set('dateFrom', '2026-10-01')->set('dateTo', '2026-10-03')->call('generate')->assertHasErrors('dateTo');

        Livewire::actingAs($admin)->test(UnsoldPage::class)->call('generate')
            ->assertSee('2026-06-01')            // Old sale: its last sale, before the period
            ->assertSee(__('never sold'));        // Dead: no sale in the data at all
    }

    public function test_an_operator_only_sees_their_shops_and_never_cost_figures(): void
    {
        $this->cost($this->dead, 12);
        $operator = User::factory()->operator()->create();
        $operator->shops()->sync([$this->b->id]);

        Livewire::actingAs($operator)->test(UnsoldPage::class)
            ->set('shopIds', [$this->a->id, $this->b->id])   // Alpha is not theirs
            ->call('generate')
            ->assertSee('Split')->assertSee('Old sale')
            ->assertDontSee(__('Value at cost'));

        Livewire::actingAs(User::factory()->create())->test(UnsoldPage::class)->call('generate')->assertSee(__('Value at cost'));
    }

    public function test_an_operator_without_shops_gets_the_hint_and_guests_are_sent_to_login(): void
    {
        Livewire::actingAs(User::factory()->operator()->create())->test(UnsoldPage::class)
            ->assertSee(__('No shops are assigned to you. Ask an admin.'));

        auth()->logout();
        $this->get(route('unsold-stock.index'))->assertRedirect();
        $this->actingAs(User::factory()->monitor()->create())->get(route('unsold-stock.index'))->assertRedirect();
    }

    public function test_the_mcp_tool_returns_the_report_without_cost_and_validates_input(): void
    {
        $tool = new UnsoldStockReport;
        $out = $tool->handle(['from' => '2026-08-08', 'to' => '2026-10-06']);

        $this->assertSame('unsold_stock', $tool->name());
        $this->assertSame(2, $out['report']['kpi']['unsold_items']);
        $this->assertFalse($out['report']['profit']);

        $this->expectException(\App\Mcp\InvalidArguments::class);
        $tool->handle(['from' => '2026-10-01', 'to' => '2026-10-03']);
    }
}
