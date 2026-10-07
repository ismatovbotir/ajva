<?php

namespace Tests\Feature;

use App\Enums\MonitorType;
use App\Jobs\ProcessReceiptIngestion;
use App\Livewire\MonitorScreen;
use App\Models\Item;
use App\Models\ItemPrice;
use App\Models\Monitor;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\ReceiptPayment;
use App\Models\Shop;
use App\Models\User;
use App\Support\MonitorScope;
use App\Support\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Concerns\InteractsWithMonitors;
use Tests\TestCase;

class MonitorTest extends TestCase
{
    use InteractsWithMonitors;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(Shop $shop, Item $item, string $at, float $qty, float $total, array $attrs = []): Receipt
    {
        $receipt = Receipt::factory()->create(array_merge([
            'shop_id' => $shop->id, 'active' => true, 'sell' => true, 'total' => $total, 'created_at' => $at,
        ], $attrs));
        ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'qty' => $qty, 'total' => $total, 'storno' => false]);

        return $receipt;
    }

    public function test_guest_is_redirected_and_user_sees_the_monitor(): void
    {
        $monitor = $this->executiveMonitor();
        $this->get('/monitors/'.$monitor->id)->assertRedirect('/login');
        $this->get('/monitor')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get('/monitors/'.$monitor->id)
            ->assertOk()
            ->assertSeeLivewire(MonitorScreen::class)
            ->assertSee('wire:poll.30s', false)
            ->assertSee($monitor->name)
            ->assertSee(MonitorType::Executive->label())
            ->assertSee(__('Waiting for the first sale today'));
    }

    public function test_hourly_payment_profit_and_ticker_numbers(): void
    {
        Carbon::setTestNow('2026-10-06 15:30:00');
        $user = User::factory()->create();
        $shop = Shop::factory()->create(['name' => 'Chilonzor']);
        $item = Item::factory()->create(['name' => 'Cola']);
        $cost = Price::factory()->create(['id' => config('inventory.cost_price_id'), 'name' => 'Cost', 'is_sell' => false]);
        ItemPrice::factory()->create(['item_id' => $item->id, 'price_id' => $cost->id, 'value' => 10]);

        $r1 = $this->sale($shop, $item, '2026-10-06 09:10:00', 2, 100);       // profit 80
        $r2 = $this->sale($shop, $item, '2026-10-06 09:40:00', 1, 50);        // profit 40
        $this->sale($shop, $item, '2026-10-06 11:00:00', 1, 30, ['sell' => false]); // refund
        $this->sale($shop, $item, '2026-10-05 09:00:00', 1, 40);               // yesterday
        ReceiptPayment::factory()->create(['receipt_id' => $r1->id, 'payment' => 'cash', 'value' => 100]);
        ReceiptPayment::factory()->create(['receipt_id' => $r2->id, 'payment' => 'card', 'value' => 50]);

        $c = $this->screen(MonitorScreen::class, $user);

        $this->assertSame('150', $c->viewData('charts')['sum'][9]['today']);
        $this->assertEquals(150.0, $c->viewData('charts')['sum'][9]['total']);
        $this->assertEquals(40.0, $c->viewData('charts')['sum'][9]['y_total']);
        $this->assertEquals(120.0, $c->viewData('profit')['total']);
        $this->assertSame(15, $c->viewData('currentHour'));

        $pay = collect($c->viewData('payments'))->keyBy('name');
        $this->assertEqualsWithDelta(66.67, $pay['cash']['percent'], 0.01);

        $latest = $c->viewData('latest');
        $this->assertCount(3, $latest);
        $this->assertTrue($latest[0]['refund']);          // newest first (11:00 refund)
        $this->assertSame('11:00:00', $latest[0]['time']);
        $this->assertSame('card', $latest[1]['payment']);
        $this->assertSame('Chilonzor', $latest[1]['shop']);

        $c->assertSee('Cola')->assertSee('Chilonzor');
    }

    public function test_empty_day_renders_a_friendly_state(): void
    {
        Carbon::setTestNow('2026-10-06 08:00:00');
        $c = $this->screen(MonitorScreen::class, User::factory()->create());

        $this->assertSame(0, $c->viewData('totals')['count']);
        $this->assertSame([], $c->viewData('latest'));
        $c->assertSee(__('Waiting for the first sale today'));
    }

    public function test_data_is_cached_per_monitor_and_cleared_for_all_when_a_receipt_is_ingested(): void
    {
        Carbon::setTestNow('2026-10-06 15:30:00');
        $user = User::factory()->create();
        $shop = Shop::factory()->create();
        $item = Item::factory()->create();
        $a = $this->executiveMonitor();
        $b = $this->executiveMonitor([$shop->id]);

        $key = fn (Monitor $m, ?array $ids) => (new MonitorScope($m, $ids, true, false, now()))->cacheKey('executive');

        Livewire::actingAs($user)->test(MonitorScreen::class, ['monitor' => $a]);
        Livewire::actingAs($user)->test(MonitorScreen::class, ['monitor' => $b]);
        $this->assertTrue(Cache::has($key($a, null)));
        $this->assertTrue(Cache::has($key($b, [$shop->id])));
        $this->assertNotSame($key($a, null), $key($b, [$shop->id]));

        ProcessReceiptIngestion::dispatchSync([
            'shop' => $shop->id,
            'pos' => 1,
            'number' => 'M-1',
            'openDate' => now()->format('d.m.y'),
            'openTime' => '10:00:00',
            'total' => 10,
            'positions' => [['item' => ['id' => $item->id], 'qty' => 1, 'totalSum' => 10]],
        ], 1);

        $this->assertFalse(Cache::has($key($a, null)));
        $this->assertFalse(Cache::has($key($b, [$shop->id])));
    }

    public function test_executive_numbers_are_restricted_to_the_monitors_shops(): void
    {
        Carbon::setTestNow('2026-10-06 15:30:00');
        $a = Shop::factory()->create(['name' => 'Alpha']);
        $b = Shop::factory()->create(['name' => 'Beta']);
        $item = Item::factory()->create();
        $this->sale($a, $item, '2026-10-06 09:00:00', 1, 100);
        $this->sale($b, $item, '2026-10-06 10:00:00', 1, 900);
        $admin = User::factory()->create();

        $onlyA = $this->executiveMonitor([$a->id]);
        $all = $this->executiveMonitor();   // nothing selected = every shop

        $c = Livewire::actingAs($admin)->test(MonitorScreen::class, ['monitor' => $onlyA]);
        $this->assertEquals(100, $c->viewData('totals')['sum']);
        $this->assertCount(1, $c->viewData('latest'));
        $c->assertSee('Alpha')->assertDontSee('Beta');

        $this->assertEquals(1000, Livewire::actingAs($admin)->test(MonitorScreen::class, ['monitor' => $all])->viewData('totals')['sum']);
    }

    public function test_the_signed_in_screen_intersects_the_monitors_shops_with_the_users_shops(): void
    {
        Carbon::setTestNow('2026-10-06 15:30:00');
        $a = Shop::factory()->create();
        $b = Shop::factory()->create();
        $c = Shop::factory()->create();
        $item = Item::factory()->create();
        $this->sale($a, $item, '2026-10-06 09:00:00', 1, 1);
        $this->sale($b, $item, '2026-10-06 09:00:00', 1, 10);
        $this->sale($c, $item, '2026-10-06 09:00:00', 1, 100);

        $monitor = $this->executiveMonitor([$a->id, $b->id]);
        $op = User::factory()->operator()->create();
        $op->shops()->sync([$b->id, $c->id]);

        // Monitor {a,b} ∩ operator {b,c} = {b}.
        $this->assertEquals(10, Livewire::actingAs($op)->test(MonitorScreen::class, ['monitor' => $monitor])->viewData('totals')['sum']);
        // Admin and monitor roles are unrestricted: the monitor's own selection.
        $this->assertEquals(11, Livewire::actingAs(User::factory()->create())->test(MonitorScreen::class, ['monitor' => $monitor])->viewData('totals')['sum']);
        $this->assertEquals(11, Livewire::actingAs(User::factory()->monitor()->create())->test(MonitorScreen::class, ['monitor' => $monitor])->viewData('totals')['sum']);

        // An operator with no shops gets the hint and no data.
        $none = User::factory()->operator()->create();
        $this->actingAs($none)->get('/monitors/'.$monitor->id)->assertOk()
            ->assertSee(__('No shops are assigned to you. Ask an admin.'));
        $this->assertEquals(0, Livewire::actingAs($none)->test(MonitorScreen::class, ['monitor' => $monitor])->viewData('totals')['sum']);

        // Operator whose shops don't overlap with the monitor's selection: no data, a different hint.
        $disjoint = User::factory()->operator()->create();
        $disjoint->shops()->sync([$c->id]);
        $this->actingAs($disjoint)->get('/monitors/'.$monitor->id)->assertOk()
            ->assertSee(e(__("None of this monitor's shops are available to you.")), false);
    }

    public function test_warehouse_monitor_never_includes_the_main_warehouse_shop(): void
    {
        $main = Shop::factory()->create();
        $a = Shop::factory()->create();
        $b = Shop::factory()->create();
        Warehouse::setShopId($main->id);

        $all = Monitor::factory()->type(MonitorType::Warehouse)->create();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $all->shopIds());

        $picked = Monitor::factory()->type(MonitorType::Warehouse)->create();
        $picked->shops()->sync([$main->id, $a->id]);
        $this->assertSame([$a->id], $picked->shopIds());

        // Other types keep it; without a configured warehouse nothing is excluded.
        $this->assertNull($this->executiveMonitor()->shopIds());
        Warehouse::setShopId(null);
        $this->assertNull(Monitor::factory()->type(MonitorType::Warehouse)->create()->shopIds());
    }

    public function test_receipts_and_warehouse_types_render_in_the_shared_shell(): void
    {
        $admin = User::factory()->create();

        foreach ([MonitorType::Receipts, MonitorType::Warehouse] as $type) {
            $monitor = Monitor::factory()->type($type)->create(['name' => 'Screen '.$type->value]);

            $this->actingAs($admin)->get('/monitors/'.$monitor->id)->assertOk()
                ->assertDontSee(__('This screen is being prepared'))
                ->assertSee('Screen '.$type->value)
                ->assertSee($type->label())
                ->assertSee('wire:poll.30s', false)
                ->assertSee(__('Fullscreen'));
        }
    }

    public function test_the_registry_maps_every_type_to_an_existing_view_and_data_method(): void
    {
        foreach (MonitorType::cases() as $type) {
            $this->assertTrue(view()->exists($type->view()), $type->value);
            $this->assertTrue(method_exists(MonitorScreen::class, $type->dataMethod()), $type->value);
            $this->assertNotSame('', $type->description());
        }
    }

    public function test_disabled_monitors_are_admin_only_when_signed_in(): void
    {
        $monitor = Monitor::factory()->disabled()->create();

        $this->actingAs(User::factory()->operator()->create())->get('/monitors/'.$monitor->id)->assertNotFound();
        $this->actingAs(User::factory()->monitor()->create())->get('/monitors/'.$monitor->id)->assertNotFound();
        $this->actingAs(User::factory()->create())->get('/monitors/'.$monitor->id)->assertOk();
    }

    public function test_the_monitors_page_redirects_to_a_single_monitor_and_lists_several(): void
    {
        $admin = User::factory()->create();

        // No monitors: friendly empty state, admins get a link to the settings.
        $this->actingAs($admin)->get('/monitor')->assertOk()
            ->assertSee(__('No monitors yet. An admin can create them in Settings > Monitors.'))
            ->assertSee(route('settings.monitors'), false);
        $this->actingAs(User::factory()->operator()->create())->get('/monitor')->assertOk()
            ->assertSee(__('No monitors yet. An admin can create them in Settings > Monitors.'))
            ->assertDontSee(route('settings.monitors'), false);

        $one = $this->executiveMonitor([], ['name' => 'Lobby TV']);
        $this->actingAs($admin)->get('/monitor')->assertRedirect(route('monitors.show', $one));

        $two = $this->executiveMonitor([], ['name' => 'Boss TV']);
        $off = Monitor::factory()->disabled()->create(['name' => 'Hidden TV']);
        $this->actingAs($admin)->get('/monitor')->assertOk()
            ->assertSee('Lobby TV')->assertSee('Boss TV')->assertSee('Hidden TV')
            ->assertSee(route('monitors.show', $two), false);

        // Non-admins don't see the disabled one.
        $this->actingAs(User::factory()->operator()->create())->get('/monitor')->assertOk()
            ->assertSee('Lobby TV')->assertDontSee('Hidden TV');

        // With only one enabled monitor left, an operator is redirected straight to it.
        $two->update(['enabled' => false]);
        $this->actingAs(User::factory()->operator()->create())->get('/monitor')->assertRedirect(route('monitors.show', $one));
    }

    public function test_monitor_role_lands_on_its_monitor(): void
    {
        $tv = User::factory()->monitor()->create();
        $monitor = $this->executiveMonitor();

        $this->actingAs($tv)->get('/monitor')->assertRedirect(route('monitors.show', $monitor));
        $this->actingAs($tv)->get('/monitors/'.$monitor->id)->assertOk()
            ->assertSee(route('logout'), false)
            ->assertDontSee('← '.__('Dashboard'), false);

        // Other monitors for the same account: the screen links back to the list.
        $this->executiveMonitor();
        $this->actingAs($tv)->get('/monitors/'.$monitor->id)->assertOk()->assertSee(__('All monitors'));
    }

    public function test_operators_only_list_monitors_that_overlap_their_shops(): void
    {
        $a = Shop::factory()->create();
        $b = Shop::factory()->create();
        $mine = $this->executiveMonitor([$a->id], ['name' => 'Mine']);
        $this->executiveMonitor([$b->id], ['name' => 'Theirs']);
        $everyone = $this->executiveMonitor([], ['name' => 'Everyone']);
        $op = User::factory()->operator()->create();
        $op->shops()->sync([$a->id]);

        $this->assertEqualsCanonicalizing([$everyone->id, $mine->id], Monitor::openableBy($op)->pluck('id')->all());
        $this->actingAs($op)->get('/monitor')->assertOk()->assertSee('Mine')->assertDontSee('Theirs');
    }

    public function test_the_admin_menu_has_no_monitor_item_but_the_settings_ones(): void
    {
        // Monitors are managed in Settings > Monitors (or reached directly by monitor-only accounts).
        $this->actingAs(User::factory()->create())->get('/')->assertOk()
            ->assertDontSee('href="'.route('monitor').'"', false)
            ->assertSee(route('settings.monitors'), false)
            ->assertSee(route('settings.warehouse'), false);

        $this->actingAs(User::factory()->operator()->create())->get('/')->assertOk()
            ->assertDontSee(route('settings.monitors'), false)
            ->assertDontSee(route('settings.warehouse'), false);
    }
}
