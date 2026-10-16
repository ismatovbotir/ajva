<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Livewire\Warehouse\OutOfStock;
use App\Mcp\Tools\WarehouseOutOfStock as OutOfStockTool;
use App\Models\Barcode;
use App\Models\Group;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use App\Services\WarehouseOutOfStock;
use App\Support\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Out of stock at the main warehouse: the definition lives in App\Services\WarehouseOutOfStock. R = 2026-10-07. */
class WarehouseOutOfStockTest extends TestCase
{
    use RefreshDatabase;

    private Shop $w;

    private Shop $a;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->w = Shop::factory()->create(['name' => 'Warehouse']);
        $this->a = Shop::factory()->create(['name' => 'Alpha']);
        Warehouse::setShopId($this->w->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function stock(Shop $shop, Item $item, float $qty, string $date = '2026-10-07'): void
    {
        Stock::query()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => $qty, 'stock_date' => $date]);
    }

    /** An item stocked at the warehouse: [date => qty] rows. */
    private function item(string $name, array $rows, ?Group $group = null): Item
    {
        $item = Item::factory()->create(['name' => $name, 'group_id' => $group?->id]);
        foreach ($rows as $date => $qty) {
            $this->stock($this->w, $item, $qty, $date);
        }

        return $item;
    }

    /** Keeps the latest warehouse sync at R and well covered (so the partial-sync guard is quiet). */
    private function baseline(): void
    {
        $this->item('Filler', ['2026-10-06' => 10, '2026-10-07' => 10]);
    }

    /** @return array<string, array<string, mixed>> out rows by item name */
    private function out(): array
    {
        return collect(app(WarehouseOutOfStock::class)->data()['rows'])->keyBy('name')->all();
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    public function test_missing_from_the_latest_sync_zero_and_in_stock(): void
    {
        $this->baseline();
        $this->item('Gone', ['2026-10-04' => 6, '2026-10-05' => 6]);
        $this->item('Zeroed', ['2026-10-03' => 4, '2026-10-06' => 1, '2026-10-07' => 0]);
        $this->item('Fine', ['2026-10-06' => 3, '2026-10-07' => 3]);

        $out = $this->out();

        $this->assertSame(['Gone', 'Zeroed'], collect($out)->keys()->sort()->values()->all());
        $this->assertSame('missing', $out['Gone']['reason']);
        $this->assertSame(2, $out['Gone']['days_out']); // R - last row date (Oct 5)
        $this->assertSame(6.0, $out['Gone']['last_qty']);
        $this->assertSame('zero', $out['Zeroed']['reason']);
        $this->assertSame('2026-10-06', $out['Zeroed']['in_stock_date']);
        $this->assertSame(1, $out['Zeroed']['days_out']);
        $this->assertFalse($out['Zeroed']['negative']);
    }

    public function test_negative_quantity_is_out_and_flagged(): void
    {
        $this->baseline();
        $this->item('Minus', ['2026-10-06' => 2, '2026-10-07' => -3]);

        $row = $this->out()['Minus'];

        $this->assertSame('zero', $row['reason']);
        $this->assertTrue($row['negative']);
        $this->assertSame(1, app(WarehouseOutOfStock::class)->data()['kpi']['negative']);
    }

    public function test_a_warehouse_behind_the_other_shops_lists_nothing(): void
    {
        $this->item('Old', ['2026-10-05' => 5]);
        $this->stock($this->a, Item::factory()->create(), 3, '2026-10-07');

        $data = app(WarehouseOutOfStock::class)->data();
        $this->assertSame('not_synced', $data['state']);
        $this->assertSame([], $data['rows']);

        Livewire::actingAs($this->admin())->test(OutOfStock::class)->call("generate")
            ->assertSee('Warehouse not synced since 2026-10-05')
            ->assertSee('data-not-synced', false)
            ->assertDontSee('Old');
    }

    public function test_nothing_is_read_until_generate_is_pressed(): void
    {
        $this->item('Old', ['2026-10-05' => 5]);
        $this->stock($this->a, Item::factory()->create(), 3, '2026-10-07');

        Livewire::actingAs($this->admin())->test(OutOfStock::class)
            ->assertSet('generated', false)->assertSeeHtml('data-generate')
            ->assertDontSee('Warehouse not synced')->assertDontSeeHtml('data-not-synced')
            ->call('generate')->assertSet('generated', true)->assertSeeHtml('data-not-synced')->assertSeeHtml('data-generate');
    }

    public function test_a_partial_sync_is_a_warning_without_kpi_figures(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $this->item('Bulk '.$i, ['2026-10-06' => 5] + ($i <= 2 ? ['2026-10-07' => 5] : []));
        }

        $data = app(WarehouseOutOfStock::class)->data();
        $this->assertSame(0.2, $data['coverage']);
        $this->assertTrue($data['partial']);
        $this->assertCount(8, $data['rows']);

        Livewire::actingAs($this->admin())->test(OutOfStock::class)->call("generate")
            ->set('needed', false)
            ->assertSee('data-partial', false)
            ->assertSee('Bulk 3')
            ->assertDontSee('data-kpi', false);
    }

    public function test_a_full_sync_shows_kpis(): void
    {
        $this->baseline();
        $this->item('Gone A', ['2026-10-05' => 5]);

        Livewire::actingAs($this->admin())->test(OutOfStock::class)->call("generate")
            ->set('needed', false)
            ->assertSee('data-kpi-out', false)
            ->assertSee('data-histogram', false)
            ->assertDontSee('data-partial', false)
            ->assertSee('can be understated');
    }

    public function test_history_cap_and_never_stocked_items(): void
    {
        $this->baseline();
        $this->item('Dead', ['2026-07-01' => 9, '2026-07-02' => 9]); // > 60 days before R
        $this->item('Never', ['2026-10-06' => 0, '2026-10-07' => 0]);
        $this->item('Recent', ['2026-09-01' => 9]); // 36 days
        $ruled = Item::factory()->create(['name' => 'No warehouse row']);
        ItemOrderRule::query()->create(['shop_id' => $this->a->id, 'item_id' => $ruled->id, 'min' => 1, 'max' => 5]);

        $data = app(WarehouseOutOfStock::class)->data();

        $this->assertSame(['Recent'], collect($data['rows'])->pluck('name')->all());
        $this->assertSame(36, $data['rows'][0]['days_out']);
        $this->assertSame(1, $data['excluded']); // ruled, but not stocked at the warehouse within the window
    }

    public function test_shop_need_speed_abc_open_orders_and_rules(): void
    {
        $this->baseline();
        $x = $this->item('Needed', ['2026-10-05' => 8]);
        $this->stock($this->a, $x, 0);
        ItemOrderRule::query()->create(['shop_id' => $this->a->id, 'item_id' => $x->id, 'min' => 2, 'max' => 20]);

        // 14 pieces sold in the shop over the last 7 days (2 a day), 100 of revenue each: the only revenue = class A.
        foreach (range(1, 7) as $d) {
            $r = Receipt::factory()->create(['shop_id' => $this->a->id, 'created_at' => Carbon::now()->subDays($d)->setTime(11, 0)]);
            ReceiptItem::factory()->create(['receipt_id' => $r->id, 'item_id' => $x->id, 'qty' => 2, 'total' => 100]);
        }
        // A warehouse-own sale is not chain demand; a refund counts as minus.
        $own = Receipt::factory()->create(['shop_id' => $this->w->id, 'created_at' => Carbon::now()->subDay()]);
        ReceiptItem::factory()->create(['receipt_id' => $own->id, 'item_id' => $x->id, 'qty' => 50, 'total' => 5000]);
        $refund = Receipt::factory()->create(['shop_id' => $this->a->id, 'sell' => false, 'created_at' => Carbon::now()->subDay()->setTime(15, 0)]);
        ReceiptItem::factory()->create(['receipt_id' => $refund->id, 'item_id' => $x->id, 'qty' => 7, 'total' => 50]);

        $order = Order::query()->create(['shop_id' => $this->a->id, 'status' => OrderStatus::Confirmed]);
        OrderItem::query()->create(['order_id' => $order->id, 'item_id' => $x->id, 'qty' => 4]);

        $row = $this->out()['Needed'];

        $this->assertSame(1, $row['shops']);
        $this->assertGreaterThan(0, $row['need']);
        $this->assertEqualsWithDelta(1.0, $row['speed'], 0.001); // (14 - 7) / 7
        $this->assertSame('A', $row['abc']);
        $this->assertSame(4.0, $row['open_orders']);
        $this->assertSame(2.0, $row['min']);
        $this->assertSame(20.0, $row['max']);
        $this->assertSame((float) $row['need'], app(WarehouseOutOfStock::class)->data()['kpi']['need']);
    }

    public function test_access_is_admin_only(): void
    {
        $operator = User::factory()->operator()->create();

        $this->get('/warehouse/out-of-stock')->assertRedirect();
        $this->actingAs($operator)->get('/warehouse/out-of-stock')->assertForbidden();
        $this->actingAs($operator)->get('/warehouse/out-of-stock/export')->assertForbidden();
        $this->actingAs($operator)->get('/warehouse/out-of-stock/print')->assertForbidden();
        Livewire::actingAs($operator)->test(OutOfStock::class)->assertForbidden();
        $this->actingAs($this->admin())->get('/warehouse/out-of-stock')->assertOk()->assertSee('Out of stock');
    }

    public function test_filters(): void
    {
        $this->baseline();
        $drinks = Group::factory()->create(['name' => 'Drinks']);
        $snacks = Group::factory()->create(['name' => 'Snacks']);
        $cola = $this->item('Cola', ['2026-10-02' => 5], $drinks); // missing, 5 days
        $this->item('Chips', ['2026-10-06' => 5, '2026-10-07' => 0], $snacks); // zero, 1 day
        Barcode::query()->create(['item_id' => $cola->id, 'gtin' => '4780000012345']);
        Item::query()->where('id', $cola->id)->update(['mark' => 'SKU-COLA']);
        $service = app(WarehouseOutOfStock::class);
        $rows = $service->data()['rows'];
        $names = fn (array $in) => collect($service->apply($rows, WarehouseOutOfStock::filters($in + ['needed' => false])))->pluck('name')->sort()->values()->all();

        $this->assertSame(['Chips', 'Cola'], $names([]));
        $this->assertSame(['Cola'], $names(['group' => $drinks->id]));
        $this->assertSame(['Chips'], $names(['reason' => 'zero']));
        $this->assertSame(['Cola'], $names(['min_days' => 3]));
        $this->assertSame(['Cola'], $names(['q' => 'sku-col']));
        $this->assertSame(['Cola'], $names(['q' => '0000012']));
        $this->assertSame(['Chips'], $names(['q' => 'chip']));
        $this->assertSame([], $names(['abc' => 'A'])); // no sales: everything is class C
        $this->assertSame(['Chips', 'Cola'], $names(['abc' => 'C']));
        // "Only items the shops need" is on by default and hides items nobody needs.
        $this->assertSame([], $service->apply($rows, WarehouseOutOfStock::filters([])));
    }

    public function test_default_sort_is_shops_then_abc_then_days_out(): void
    {
        $base = ['need' => 1, 'speed' => 0, 'group_id' => null, 'reason' => 'zero', 'sku' => null];
        $rows = [
            ['name' => 'a', 'shops' => 1, 'abc' => 'C', 'days_out' => 9, 'item_id' => 1] + $base,
            ['name' => 'b', 'shops' => 3, 'abc' => 'C', 'days_out' => 1, 'item_id' => 2] + $base,
            ['name' => 'c', 'shops' => 1, 'abc' => 'A', 'days_out' => 2, 'item_id' => 3] + $base,
            ['name' => 'd', 'shops' => 1, 'abc' => 'A', 'days_out' => 5, 'item_id' => 4] + $base,
        ];

        $sorted = app(WarehouseOutOfStock::class)->apply($rows, WarehouseOutOfStock::filters([]));

        $this->assertSame(['b', 'd', 'c', 'a'], array_column($sorted, 'name'));
    }

    public function test_csv_has_a_bom_the_columns_and_the_filters(): void
    {
        $this->baseline();
        $this->item('=Evil, "quoted"', ['2026-10-05' => 5]);
        $this->item('Plain', ['2026-10-06' => 5, '2026-10-07' => 0]);

        $response = $this->actingAs($this->admin())->get('/warehouse/out-of-stock/export?needed=0&reason=missing');
        $body = $response->streamedContent();

        $response->assertOk();
        $this->assertStringStartsWith("\xEF\xBB\xBF".'sku,name,group,abc,reason,last_qty,last_in_stock_date,days_out,chain_speed_per_day,shops_needing,need_qty,open_orders,min,max', $body);
        $this->assertStringContainsString('"\'=Evil, ""quoted"""', $body);
        $this->assertStringNotContainsString('Plain', $body);
        $this->assertDoesNotMatchRegularExpression('/cost|profit|price/i', strtok($body, "\n"));
    }

    public function test_print_view_groups_by_item_group_and_shows_the_sync_date(): void
    {
        $this->baseline();
        $this->item('Cola', ['2026-10-05' => 5], Group::factory()->create(['name' => 'Drinks']));

        $this->actingAs($this->admin())->get('/warehouse/out-of-stock/print?needed=0')
            ->assertOk()
            ->assertSee('Warehouse stock as of 2026-10-07.')
            ->assertSee('Drinks')
            ->assertSee('Cola');
    }

    public function test_the_warehouse_not_being_set_shows_a_setup_banner(): void
    {
        Warehouse::setShopId(null);

        $this->assertSame('unconfigured', app(WarehouseOutOfStock::class)->data()['state']);
        Livewire::actingAs($this->admin())->test(OutOfStock::class)->call("generate")
            ->assertSee('data-no-warehouse', false)
            ->assertSee(route('settings.warehouse'), false);
    }

    public function test_the_page_lists_rows_and_the_menu_entry_is_admin_only(): void
    {
        $this->baseline();
        $this->item('Gone', ['2026-10-05' => 5]);

        Livewire::actingAs($this->admin())->test(OutOfStock::class)->call("generate")
            ->set('needed', false)
            ->assertSee('Gone')
            ->set('search', 'zzz')
            ->assertDontSee('Gone');

        $this->actingAs($this->admin())->get(route('dashboard'))->assertSee(route('warehouse.out-of-stock'), false);
        $this->actingAs(User::factory()->operator()->create())->get(route('dashboard'))->assertDontSee(route('warehouse.out-of-stock'), false);
    }

    public function test_mcp_tool_is_compact_and_has_no_cost_fields(): void
    {
        $this->baseline();
        $this->item('Gone', ['2026-10-05' => 5]);

        $result = (new OutOfStockTool)->handle(['only_needed' => false]);

        $this->assertSame('ok', $result['state']);
        $this->assertSame('Gone', $result['items'][0]['item']);
        $this->assertSame('missing', $result['items'][0]['reason']);
        $this->assertDoesNotMatchRegularExpression('/cost|profit|price|margin/i', json_encode(array_keys($result['items'][0])));
    }
}
