<?php

namespace Tests\Feature;

use App\Livewire\Ordering\Index;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use App\Services\OrderingReport;
use App\Support\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

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

    private function item(string $name, Shop $shop, float $stock, float $min, float $max, string $stockDate = '2026-10-07'): Item
    {
        $item = Item::factory()->create(['name' => $name]);
        Stock::query()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => $stock, 'stock_date' => $stockDate]);
        ItemOrderRule::query()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'min' => $min, 'max' => $max]);

        return $item;
    }

    private function receipt(Shop $shop, Item $item, float $qty, string $at, bool $sell = true, bool $active = true, bool $storno = false): void
    {
        $receipt = Receipt::factory()->create(['shop_id' => $shop->id, 'active' => $active, 'sell' => $sell, 'created_at' => $at]);
        ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'qty' => $qty, 'storno' => $storno]);
    }

    /** @return array<string, array<string, mixed>> rows of $shop keyed by item name */
    private function rows(array $report, Shop $shop): array
    {
        $rows = collect($report['shops'])->firstWhere('id', $shop->id)['rows'];

        return collect($rows)->keyBy('item')->all();
    }

    public function test_orders_are_calculated_from_stock_min_max_and_net_sales(): void
    {
        $x = $this->item('X', $this->a, 30, 10, 50);   // 25 sold -> estimated 5  -> order 45
        $y = $this->item('Y', $this->a, 12, 10, 40);   // 12 sold, 2 refunded -> net 10 -> estimated 2 -> order 38
        $this->item('Z', $this->a, 8, 5, 20);          // 8 > min 5, nothing sold -> not listed
        $this->item('W', $this->a, 3, 10, 30);         // no sales, 3 <= 10 -> order 27
        $this->item('V', $this->a, 0, 5, 15);          // out of stock -> order 15
        $this->item('NoMax', $this->a, 0, 5, 0);       // a rule with max 0 is ignored

        $this->receipt($this->a, $x, 25, '2026-10-07 09:00:00');
        $this->receipt($this->a, $x, 99, '2026-10-07 09:30:00', storno: true);   // storno lines never count
        $this->receipt($this->a, $x, 99, '2026-10-07 09:45:00', active: false);  // cancelled receipts never count
        $this->receipt($this->a, $y, 12, '2026-10-07 10:00:00');
        $this->receipt($this->a, $y, 2, '2026-10-07 11:00:00', sell: false);     // refund puts 2 back

        $rows = $this->rows(app(OrderingReport::class)->build(), $this->a);

        $this->assertSame(['V', 'Y', 'W', 'X'], array_keys($rows), 'most urgent first: out of stock, then lowest cover');

        $this->assertEquals(45, $rows['X']['order']);
        $this->assertEquals(25, $rows['X']['net_sold']);
        $this->assertEquals(5, $rows['X']['estimated']);
        $this->assertSame('low', $rows['X']['level']);

        $this->assertEquals(10, $rows['Y']['net_sold']);
        $this->assertEquals(38, $rows['Y']['order']);

        $this->assertEquals(27, $rows['W']['order']);
        $this->assertSame('out', $rows['V']['level']);
        $this->assertEquals(15, $rows['V']['order']);
        $this->assertArrayNotHasKey('Z', $rows);
        $this->assertArrayNotHasKey('NoMax', $rows);
    }

    public function test_only_sales_from_the_snapshot_day_onwards_reduce_the_stock(): void
    {
        $u = $this->item('U', $this->a, 20, 10, 40, '2026-10-05');

        $this->receipt($this->a, $u, 15, '2026-10-04 15:00:00');   // before the snapshot: already in the 1C figure
        $this->receipt($this->a, $u, 12, '2026-10-06 15:00:00');   // after it: reduces the stock

        $row = $this->rows(app(OrderingReport::class)->build(), $this->a)['U'];

        $this->assertEquals(12, $row['net_sold']);
        $this->assertEquals(8, $row['estimated']);
        $this->assertEquals(32, $row['order']);
        $this->assertSame('2026-10-05', $row['stock_date']);
    }

    public function test_estimated_stock_never_goes_below_zero(): void
    {
        $i = $this->item('Oversold', $this->a, 5, 10, 30);
        $this->receipt($this->a, $i, 50, '2026-10-07 10:00:00');

        $row = $this->rows(app(OrderingReport::class)->build(), $this->a)['Oversold'];

        $this->assertEquals(0, $row['estimated']);
        $this->assertEquals(30, $row['order']);
        $this->assertSame('out', $row['level']);
    }

    public function test_each_shop_gets_its_own_list_and_scope_limits_the_shops(): void
    {
        $this->item('Only A', $this->a, 1, 10, 20);
        $this->item('Only B', $this->b, 1, 10, 20);

        $all = app(OrderingReport::class)->build();
        $this->assertSame(['Alpha', 'Beta'], array_column($all['shops'], 'name'));
        $this->assertSame(2, $all['total_items']);
        $this->assertSame(['Only A'], array_keys($this->rows($all, $this->a)));
        $this->assertSame(['Only B'], array_keys($this->rows($all, $this->b)));

        $scoped = app(OrderingReport::class)->build([$this->a->id]);
        $this->assertSame(['Alpha'], array_column($scoped['shops'], 'name'));
        $this->assertSame(1, $scoped['total_items']);

        $excluded = app(OrderingReport::class)->build(null, [$this->a->id]);   // e.g. the main warehouse
        $this->assertSame(['Beta'], array_column($excluded['shops'], 'name'));
    }

    public function test_history_rows_do_not_change_the_current_stock_used(): void
    {
        $i = $this->item('H', $this->a, 100, 10, 50, '2026-10-01');                       // old snapshot: plenty
        Stock::query()->create(['shop_id' => $this->a->id, 'item_id' => $i->id, 'qty' => 4, 'stock_date' => '2026-10-07']);   // newest: low

        $row = $this->rows(app(OrderingReport::class)->build(), $this->a)['H'];

        $this->assertEquals(4, $row['stock']);
        $this->assertEquals(46, $row['order']);
    }

    public function test_the_page_lists_one_tab_per_shop_and_refresh_recalculates(): void
    {
        $x = $this->item('X', $this->a, 30, 10, 50);
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(Index::class);
        $this->assertCount(2, $component->viewData('shops'));
        $this->assertSame($this->a->id, $component->viewData('active')['id']);   // first shop with something to order
        $this->assertSame(0, $component->viewData('report')['total_items']);     // 30 > min 10

        // A sale lands directly in the DB: the cached report doesn't know until Refresh.
        $this->receipt($this->a, $x, 25, '2026-10-07 11:00:00');
        $this->assertSame(0, $component->viewData('report')['total_items']);

        $component->call('refresh');
        $this->assertSame(1, $component->viewData('report')['total_items']);
        $component->assertSee(__('Order'))->assertSee('X');
    }

    public function test_operators_only_get_their_shops_and_cannot_select_others(): void
    {
        $this->item('Only A', $this->a, 1, 10, 20);
        $this->item('Only B', $this->b, 1, 10, 20);

        $operator = User::factory()->operator()->create();
        $operator->shops()->attach($this->a->id);

        $component = Livewire::actingAs($operator)->test(Index::class);
        $this->assertSame(['Alpha'], $component->viewData('shops')->pluck('name')->all());
        $component->assertSee('Only A')->assertDontSee('Only B');

        // A forged shop id is a 404, and a forged property value falls back to an allowed tab.
        $component->call('selectShop', $this->b->id)->assertNotFound();
        $forged = Livewire::actingAs($operator)->test(Index::class)->set('shopId', $this->b->id);
        $this->assertSame($this->a->id, $forged->viewData('active')['id']);
    }

    public function test_the_main_warehouse_is_never_listed_as_a_shop_to_replenish(): void
    {
        $this->item('Wh item', $this->a, 1, 10, 20);
        $this->item('Shop item', $this->b, 1, 10, 20);
        Warehouse::setShopId($this->a->id);   // Alpha is the main warehouse

        Livewire::actingAs(User::factory()->create())->test(Index::class)
            ->assertSee('Shop item')
            ->assertDontSee('Wh item')
            ->assertDontSee('Alpha');
    }

    public function test_route_menu_and_roles(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->get('/ordering')->assertOk()->assertSeeLivewire(Index::class)
            ->assertSee(route('ordering.index'), false);

        $this->actingAs(User::factory()->operator()->create())->get('/ordering')->assertOk();
        $this->actingAs(User::factory()->monitor()->create())->get('/ordering')->assertRedirect();   // monitor accounts only see monitors

        auth()->logout();
        $this->get('/ordering')->assertRedirect('/login');
    }

    public function test_an_operator_without_shops_sees_nothing(): void
    {
        $this->item('Only A', $this->a, 1, 10, 20);

        Livewire::actingAs(User::factory()->operator()->create())->test(Index::class)
            ->assertSee(__('No shops are assigned to you. Ask an admin.'))
            ->assertDontSee('Only A');
    }
}
