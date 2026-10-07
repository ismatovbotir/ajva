<?php

namespace Tests\Feature;

use App\Jobs\ProcessReceiptIngestion;
use App\Livewire\Analytics\Index as AnalyticsIndex;
use App\Livewire\Dashboard;
use App\Livewire\Items\Show as ItemsShow;
use App\Livewire\MonitorScreen;
use App\Livewire\Pos\Index as PosIndex;
use App\Livewire\Receipts\Index as ReceiptsIndex;
use App\Livewire\SalesBoard;
use App\Livewire\Users\Index as UsersIndex;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\Monitor;
use App\Models\Pos;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use App\Support\ShopAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithMonitors;
use Tests\TestCase;

class ShopAccessTest extends TestCase
{
    use InteractsWithMonitors;
    use RefreshDatabase;

    private Shop $a;

    private Shop $b;

    private Item $item;

    private Receipt $receiptA;

    private Receipt $receiptB;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-06 15:30:00');

        $this->a = Shop::factory()->create(['name' => 'Shop Alpha']);
        $this->b = Shop::factory()->create(['name' => 'Shop Beta']);
        $this->item = Item::factory()->create(['name' => 'Cola']);

        foreach ([[$this->a, 10], [$this->b, 70]] as [$shop, $qty]) {
            Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $this->item->id, 'qty' => $qty]);
            ItemOrderRule::query()->create(['shop_id' => $shop->id, 'item_id' => $this->item->id, 'min' => 100, 'max' => 200]);
        }

        $this->receiptA = $this->sale($this->a, 100);
        $this->receiptB = $this->sale($this->b, 900);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(Shop $shop, float $total): Receipt
    {
        $receipt = Receipt::factory()->create([
            'shop_id' => $shop->id, 'active' => true, 'sell' => true, 'total' => $total, 'created_at' => '2026-10-06 10:00:00',
        ]);
        ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $this->item->id, 'qty' => 1, 'total' => $total, 'storno' => false]);

        return $receipt;
    }

    private function operator(Shop ...$shops): User
    {
        $user = User::factory()->operator()->create();
        $user->shops()->sync(array_map(fn (Shop $s) => $s->id, $shops));

        return $user;
    }

    public function test_helper_returns_null_for_admin_and_monitor_and_ids_for_operator(): void
    {
        $this->assertNull(ShopAccess::ids(User::factory()->create()));
        $this->assertNull(ShopAccess::ids(User::factory()->monitor()->create()));
        $this->assertSame([$this->a->id], ShopAccess::ids($this->operator($this->a)));
        $this->assertSame([], ShopAccess::ids($this->operator()));
        $this->assertNotSame(ShopAccess::scopeKey([1, 2]), ShopAccess::scopeKey([1]));
        $this->assertSame(ShopAccess::scopeKey([2, 1]), ShopAccess::scopeKey([1, 2]));
    }

    public function test_shops_list_and_show(): void
    {
        $op = $this->operator($this->a);

        $this->actingAs($op)->get('/shops')->assertOk()->assertSee('Shop Alpha')->assertDontSee('Shop Beta');
        $this->actingAs($op)->get('/shops/'.$this->a->id)->assertOk();
        $this->actingAs($op)->get('/shops/'.$this->b->id)->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/shops/'.$this->b->id)->assertOk();
    }

    public function test_receipts_list_show_and_day_analytics(): void
    {
        $op = $this->operator($this->a);

        $this->actingAs($op)->get('/receipts/'.$this->receiptA->id)->assertOk();
        $this->actingAs($op)->get('/receipts/'.$this->receiptB->id)->assertNotFound();

        $component = Livewire::actingAs($op)->test(ReceiptsIndex::class);
        $this->assertSame([$this->receiptA->id], $component->viewData('receipts')->pluck('id')->all());
        $this->assertEquals(100, $component->viewData('summary')['total']);
        $this->assertEquals(1, $component->viewData('summary')['count']);
        $this->assertSame(['Shop Alpha'], array_column($component->viewData('legend'), 'name'));

        $admin = Livewire::actingAs(User::factory()->create())->test(ReceiptsIndex::class);
        $this->assertEquals(1000, $admin->viewData('summary')['total']);
    }

    public function test_analytics_tabs_rows_and_forged_shop_selection(): void
    {
        $op = $this->operator($this->a);

        $component = Livewire::actingAs($op)->test(AnalyticsIndex::class)->call('generate');
        $this->assertSame([$this->a->id], $component->viewData('tabs')->pluck('id')->all());

        $component->call('selectShop', $this->b->id)->assertNotFound();

        // A forged property value is never used: the component falls back to an allowed tab.
        $forged = Livewire::actingAs($op)->test(AnalyticsIndex::class)->call('generate')->set('shopId', $this->b->id);
        $this->assertSame($this->a->id, $forged->viewData('tabs')->first()['id']);
        $this->assertEquals(10, $forged->viewData('rows')->first()['stock']);

        $forged->call('showReceipts', $this->item->id);
        $this->assertSame([$this->receiptA->id], $forged->viewData('modalReceipts')->pluck('id')->all());
    }

    public function test_dashboard_figures_exclude_other_shops(): void
    {
        $op = $this->operator($this->a);

        $component = Livewire::actingAs($op)->test(Dashboard::class);
        $this->assertSame(['Shop Alpha'], array_column($component->viewData('shopRows'), 'name'));
        $this->assertSame(1, $component->viewData('exceptions')['total']);
        $this->assertSame(1, $component->viewData('coverage')['total']);

        $admin = Livewire::actingAs(User::factory()->create())->test(Dashboard::class);
        $this->assertCount(2, $admin->viewData('shopRows'));
        $this->assertSame(2, $admin->viewData('exceptions')['total']);
    }

    public function test_sales_board_and_monitor_numbers_exclude_other_shops(): void
    {
        $op = $this->operator($this->a);

        foreach ([SalesBoard::class, MonitorScreen::class] as $class) {
            $this->assertEquals(100, $this->screen($class, $op)->viewData('totals')['sum'], $class);
            $this->assertEquals(1000, $this->screen($class, User::factory()->create())->viewData('totals')['sum'], $class);
            // The monitor role is unrestricted.
            $this->assertEquals(1000, $this->screen($class, User::factory()->monitor()->create())->viewData('totals')['sum'], $class);
        }

        $monitor = $this->screen(MonitorScreen::class, $op);
        $this->assertSame([$this->receiptA->id], array_column($monitor->viewData('latest'), 'id'));
        $this->assertSame(1, $monitor->viewData('alerts')['below_min']);
    }

    public function test_operator_without_shops_sees_nothing_and_a_hint(): void
    {
        $op = $this->operator();
        $hint = __('No shops are assigned to you. Ask an admin.');

        $this->actingAs($op)->get('/shops')->assertOk()->assertDontSee('Shop Alpha')->assertSee($hint);
        $this->actingAs($op)->get('/')->assertOk()->assertSee($hint);
        $this->actingAs($op)->get('/monitors/'.$this->executiveMonitor()->id)->assertOk()->assertSee($hint);
        $this->actingAs($op)->get('/receipts')->assertOk()->assertSee($hint);
        $this->actingAs($op)->get('/analytics')->assertOk()->assertSee($hint);
        $this->actingAs($op)->get('/pos')->assertOk()->assertSee($hint);

        $this->assertEquals(0, Livewire::actingAs($op)->test(SalesBoard::class)->viewData('totals')['sum']);
        $this->assertSame([], Livewire::actingAs($op)->test(Dashboard::class)->viewData('shopRows'));
        $this->assertCount(0, Livewire::actingAs($op)->test(ReceiptsIndex::class)->viewData('receipts'));
        $this->assertCount(0, Livewire::actingAs($op)->test(AnalyticsIndex::class)->call('generate')->viewData('tabs'));
    }

    public function test_items_show_stock_and_rules_tabs_are_filtered(): void
    {
        $component = Livewire::actingAs($this->operator($this->a))->test(ItemsShow::class, ['item' => $this->item]);

        $this->assertSame([$this->a->id], $component->viewData('stocks')->pluck('shop_id')->all());
        $this->assertSame([$this->a->id], $component->viewData('orderRules')->pluck('shop_id')->all());
        // The catalogue itself stays global.
        $this->actingAs($this->operator())->get('/items')->assertOk()->assertSee('Cola');
    }

    public function test_pos_list_and_actions_are_scoped(): void
    {
        Pos::factory()->create(['shop_id' => $this->a->id, 'name' => 'Till A']);
        $posB = Pos::factory()->create(['shop_id' => $this->b->id, 'name' => 'Till B']);
        $op = $this->operator($this->a);

        Livewire::actingAs($op)->test(PosIndex::class)
            ->assertSee('Till A')->assertDontSee('Till B')
            ->call('delete', $posB->id)->assertNotFound();
        Livewire::actingAs($op)->test(PosIndex::class)
            ->set('name', 'X')->set('shop_id', $this->b->id)->call('save')->assertHasErrors('shop_id');

        $this->assertNotNull($posB->fresh());
    }

    public function test_two_operators_never_share_cached_numbers_and_ingestion_invalidates_every_scope(): void
    {
        $opA = $this->operator($this->a);
        $opB = $this->operator($this->b);
        $admin = User::factory()->create();

        foreach ([SalesBoard::class, MonitorScreen::class] as $class) {
            // Same day, same cache store: each scope keeps its own entry.
            $this->assertEquals(100, $this->screen($class, $opA)->viewData('totals')['sum']);
            $this->assertEquals(900, $this->screen($class, $opB)->viewData('totals')['sum']);
            $this->assertEquals(1000, $this->screen($class, $admin)->viewData('totals')['sum']);
            $this->assertEquals(100, $this->screen($class, $opA)->viewData('totals')['sum']);
        }

        $pos = Pos::factory()->create(['shop_id' => $this->a->id]);
        ProcessReceiptIngestion::dispatchSync([
            'shop' => $this->a->id,
            'number' => 'R-NEW',
            'openDate' => '06.10.26',
            'openTime' => '11:00:00',
            'total' => 5,
            'positions' => [['item' => ['id' => $this->item->id], 'qty' => 1, 'totalSum' => 5]],
        ], $pos->id);

        foreach ([SalesBoard::class, MonitorScreen::class] as $class) {
            $this->assertEquals(105, $this->screen($class, $opA)->viewData('totals')['sum']);
            $this->assertEquals(900, $this->screen($class, $opB)->viewData('totals')['sum']);
            $this->assertEquals(1005, $this->screen($class, $admin)->viewData('totals')['sum']);
        }
    }

    public function test_public_monitor_stays_global_even_for_a_signed_in_operator(): void
    {
        $token = Monitor::factory()->withLink()->create()->token;

        $this->actingAs($this->operator($this->a));
        $component = Livewire::test(MonitorScreen::class, ['token' => $token]);

        $this->assertEquals(1000, $component->viewData('totals')['sum']);
    }

    public function test_users_modal_assigns_clears_and_ignores_shops_for_other_roles(): void
    {
        $admin = User::factory()->create();
        $target = User::factory()->operator()->create();

        $component = Livewire::actingAs($admin)->test(UsersIndex::class)
            ->call('edit', $target->id)
            ->set('shopIds', [(string) $this->a->id, (string) $this->b->id])
            ->call('save')
            ->assertHasNoErrors();
        $this->assertEqualsCanonicalizing([$this->a->id, $this->b->id], $target->shops()->pluck('shops.id')->all());

        $component->call('edit', $target->id)->call('clearShops')->call('selectAllShops');
        $this->assertCount(Shop::query()->count(), $component->get('shopIds'));
        $component->call('clearShops')->set('shopIds', [(string) $this->a->id])->call('save');
        $this->assertSame([$this->a->id], $target->shops()->pluck('shops.id')->all());

        // Unknown shop ids are rejected.
        $component->call('edit', $target->id)->set('shopIds', ['9999'])->call('save')->assertHasErrors('shopIds.0');

        // Switching to a non-operator role drops the assignment.
        $component->call('edit', $target->id)->set('role', 'monitor')->set('shopIds', [(string) $this->a->id])->call('save');
        $this->assertSame(0, $target->shops()->count());
        $this->assertNull(ShopAccess::ids($target->fresh()));

        // A new admin never gets assignments even when ids are posted.
        $component->call('create')->set('name', 'New')->set('email', 'new@example.com')->set('password', 'secret-pass')
            ->set('role', 'admin')->set('shopIds', [(string) $this->a->id])->call('save')->assertHasNoErrors();
        $this->assertSame(0, User::query()->where('email', 'new@example.com')->first()->shops()->count());
    }

    public function test_users_list_shows_assigned_shops(): void
    {
        $this->operator($this->a, $this->b);

        Livewire::actingAs(User::factory()->create())->test(UsersIndex::class)
            ->assertSee('Shop Alpha, Shop Beta');
    }
}
