<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use App\Livewire\Orders\PublicDraft;
use App\Livewire\Orders\Show;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\Order;
use App\Models\OrderLink;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use App\Services\ForecastService;
use App\Services\OrderRecommendations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Close the loop: the shop confirms receipt (phone link + signed-in page), and received / old orders stop counting as in transit. */
class OrderReceivingTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Shop $other;

    private Item $cola;

    private Item $water;

    private Order $order;

    private User $aziz;

    private User $admin;

    private OrderLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->shop = Shop::factory()->create(['name' => 'Alpha']);
        $this->other = Shop::factory()->create(['name' => 'Beta']);
        $this->cola = Item::factory()->create(['name' => 'Cola']);
        $this->water = Item::factory()->create(['name' => 'Water']);
        $this->admin = User::factory()->create();
        $this->aziz = $this->operator($this->shop);
        $this->order = $this->confirmedOrder($this->shop);
        $this->link = OrderLink::forUser($this->order, $this->aziz);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function operator(Shop $shop): User
    {
        $user = User::factory()->operator()->create();
        $user->shops()->attach($shop->id);

        return $user;
    }

    private function confirmedOrder(Shop $shop, ?Carbon $at = null): Order
    {
        $order = Order::query()->create([
            'shop_id' => $shop->id, 'status' => OrderStatus::Confirmed, 'confirmed_at' => $at ?? now(),
            'sync_status' => OrderSyncStatus::Synced,
        ]);
        $order->items()->create(['item_id' => $this->cola->id, 'qty' => 10]);
        $order->items()->create(['item_id' => $this->water->id, 'qty' => 4]);

        return $order;
    }

    private function line(Item $item): \App\Models\OrderItem
    {
        return $this->order->items()->where('item_id', $item->id)->firstOrFail();
    }

    // ---------------------------------------------------------------- phone page

    public function test_the_link_of_a_confirmed_order_shows_the_receipt_form_only(): void
    {
        $this->get('/o/'.$this->link->token)->assertOk()->assertSee('Confirm receipt')->assertDontSee('data-lines', false);

        $open = fn () => Livewire::test(PublicDraft::class, ['token' => $this->link->token]);
        $open()->assertSet('recvQty.'.$this->cola->id, '10')->assertSet('recvQty.'.$this->water->id, '4');
        $open()->call('saveQty', $this->cola->id, '1')->assertNotFound();
        $open()->call('addItem', $this->water->id)->assertNotFound();
        $open()->call('confirm')->assertNotFound();
        $this->assertEquals(10, $this->line($this->cola)->qty);
    }

    public function test_receiving_through_the_link_fulfils_the_order_and_records_it(): void
    {
        Livewire::test(PublicDraft::class, ['token' => $this->link->token])->call('receive')->assertSee('Receipt confirmed');

        $order = $this->order->fresh();
        $this->assertSame(OrderStatus::Fulfilled, $order->status);
        $this->assertNotNull($order->received_at);
        $this->assertSame($this->aziz->id, $order->received_by);
        $this->assertSame('received', $order->close_reason);
        $this->assertEquals(10, $this->line($this->cola)->qty_received);
        $this->assertNull($this->line($this->cola)->discrepancy_reason);

        $event = $order->events()->where('action', 'received')->whereNull('item_id')->firstOrFail();
        $this->assertSame([$this->aziz->id, 'link', '0'], [$event->user_id, $event->channel, $event->note]);

        // Done: the link is dead.
        $this->get('/o/'.$this->link->token)->assertNotFound();
    }

    public function test_a_different_quantity_and_reason_are_stored_and_recorded_per_line(): void
    {
        Livewire::test(PublicDraft::class, ['token' => $this->link->token])
            ->set('recvQty.'.$this->cola->id, '7')
            ->set('recvReason.'.$this->cola->id, 'damaged')
            ->set('recvReason.'.$this->water->id, 'missing')   // same quantity: the reason is dropped
            ->call('receive');

        $this->assertEquals(7, $this->line($this->cola)->qty_received);
        $this->assertSame('damaged', $this->line($this->cola)->discrepancy_reason);
        $this->assertEquals(4, $this->line($this->water)->qty_received);
        $this->assertNull($this->line($this->water)->discrepancy_reason);

        $lineEvent = $this->order->events()->where('action', 'received')->where('item_id', $this->cola->id)->firstOrFail();
        $this->assertEquals([10, 7, 'damaged'], [$lineEvent->old_qty, $lineEvent->new_qty, $lineEvent->note]);
        $this->assertSame('1', $this->order->events()->where('action', 'received')->whereNull('item_id')->value('note'));
    }

    public function test_a_bad_received_quantity_is_refused_and_nothing_changes(): void
    {
        foreach (['abc', '-1', '9999999'] as $bad) {
            Livewire::test(PublicDraft::class, ['token' => $this->link->token])
                ->set('recvQty.'.$this->cola->id, $bad)->call('receive')->assertHasErrors('receive');
        }

        $this->assertSame(OrderStatus::Confirmed, $this->order->fresh()->status);
        $this->assertNull($this->line($this->cola)->qty_received);
        $this->assertSame(0, $this->order->events()->where('action', 'received')->count());
    }

    public function test_the_link_user_must_still_be_active_and_assigned(): void
    {
        $this->aziz->update(['is_active' => false]);
        $this->get('/o/'.$this->link->token)->assertNotFound();

        $this->aziz->update(['is_active' => true]);
        $this->aziz->shops()->detach();
        $this->get('/o/'.$this->link->token)->assertNotFound();
    }

    public function test_a_cancelled_or_deleted_order_link_is_404_and_an_open_page_cannot_receive_it(): void
    {
        $component = Livewire::test(PublicDraft::class, ['token' => $this->link->token]);

        $this->order->update(['status' => OrderStatus::Cancelled]);

        $component->call('receive')->assertNotFound();
        $this->get('/o/'.$this->link->token)->assertNotFound();
        $this->assertNull($this->order->fresh()->received_at);
    }

    public function test_an_order_cannot_be_received_twice(): void
    {
        $component = Livewire::test(PublicDraft::class, ['token' => $this->link->token]);
        Livewire::test(PublicDraft::class, ['token' => $this->link->token])->call('receive');

        $component->call('receive')->assertNotFound();
        $this->assertSame(1, $this->order->events()->where('action', 'received')->whereNull('item_id')->count());
    }

    // ---------------------------------------------------------------- signed-in page

    public function test_the_shops_operator_and_an_admin_receive_on_the_order_page(): void
    {
        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $this->order])
            ->assertSee('Confirm receipt')
            ->set('recvQty.'.$this->cola->id, '9')
            ->call('receive');

        $order = $this->order->fresh();
        $this->assertSame(OrderStatus::Fulfilled, $order->status);
        $this->assertSame([$this->aziz->id, 9.0], [$order->received_by, (float) $this->line($this->cola)->qty_received]);
        $this->assertSame('panel', $order->events()->where('action', 'received')->whereNull('item_id')->value('channel'));

        $second = $this->confirmedOrder($this->shop);
        Livewire::actingAs($this->admin)->test(Show::class, ['order' => $second])->call('receive');
        $this->assertSame(OrderStatus::Fulfilled, $second->fresh()->status);
    }

    public function test_another_shops_operator_cannot_see_or_receive_the_order(): void
    {
        $stranger = $this->operator($this->other);

        Livewire::actingAs($stranger)->test(Show::class, ['order' => $this->order])->assertNotFound();
        $this->assertSame(OrderStatus::Confirmed, $this->order->fresh()->status);
    }

    public function test_a_received_order_refuses_a_second_receipt_and_a_draft_cannot_be_received(): void
    {
        $component = Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $this->order]);
        $component->call('receive');
        $component->call('receive')->assertForbidden();

        $draft = Order::query()->create(['shop_id' => $this->shop->id, 'status' => OrderStatus::Draft]);
        $draft->items()->create(['item_id' => $this->cola->id, 'qty' => 1]);
        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $draft])->call('receive')->assertForbidden();
    }

    // ---------------------------------------------------------------- in transit

    private function ruledItem(string $name, Shop $shop, float $stock, float $min, float $max): Item
    {
        $item = Item::factory()->create(['name' => $name]);
        Stock::query()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => $stock, 'stock_date' => '2026-10-07']);
        ItemOrderRule::query()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'min' => $min, 'max' => $max]);

        return $item;
    }

    private function inTransit(Shop $shop, Item $item): float
    {
        $row = collect(app(ForecastService::class)->build([$shop->id], [])['rows'])->firstWhere('item_id', $item->id);

        return (float) $row['in_transit'];
    }

    public function test_a_confirmed_order_counts_as_in_transit_until_it_is_received(): void
    {
        $item = $this->ruledItem('Juice', $this->shop, 0, 5, 10);
        $order = $this->confirmedOrder($this->shop);
        $order->items()->create(['item_id' => $item->id, 'qty' => 3]);

        $this->assertEquals(3, $this->inTransit($this->shop, $item));

        app(\App\Services\OrderReceiving::class)->receive($order->fresh(), $this->aziz, 'panel', []);

        $this->assertEquals(0, $this->inTransit($this->shop, $item));
    }

    public function test_an_order_older_than_the_window_stops_counting_without_any_flag(): void
    {
        $item = $this->ruledItem('Juice', $this->shop, 0, 5, 10);
        $old = $this->confirmedOrder($this->shop, now()->subHours(25));
        $old->items()->create(['item_id' => $item->id, 'qty' => 3]);
        $this->assertEquals(0, $this->inTransit($this->shop, $item));   // default window: 1 day

        config(['inventory.in_transit_max_days' => 2]);
        $this->assertEquals(3, $this->inTransit($this->shop, $item));
    }

    private function warehouseScenario(): Item
    {
        $wh = Shop::factory()->create(['name' => 'Main warehouse']);
        $item = $this->ruledItem('Juice', $this->shop, 0, 5, 10);
        Stock::query()->create(['shop_id' => $wh->id, 'item_id' => $item->id, 'qty' => 12, 'stock_date' => '2026-10-07']);
        $this->warehouse = $wh;

        return $item;
    }

    private Shop $warehouse;

    private function available(): float
    {
        $rec = app(OrderRecommendations::class)->build(null, $this->warehouse->id);

        return (float) $rec['pick'][0]['available'];
    }

    public function test_the_warehouse_only_loses_orders_confirmed_since_its_latest_stock_day(): void
    {
        $item = $this->warehouseScenario();
        config(['inventory.in_transit_max_days' => 3]);

        // Confirmed the day before the 1C snapshot (2026-10-07): the snapshot already reflects the shipment.
        $before = $this->confirmedOrder($this->other, Carbon::parse('2026-10-06 22:00'));
        $before->items()->create(['item_id' => $item->id, 'qty' => 4]);
        $this->assertEquals(12, $this->available());

        // Confirmed on the snapshot day: conservatively subtracted.
        $same = $this->confirmedOrder($this->other, Carbon::parse('2026-10-07 09:00'));
        $same->items()->create(['item_id' => $item->id, 'qty' => 5]);
        $this->assertEquals(7, $this->available());

        // Received: not subtracted any more.
        app(\App\Services\OrderReceiving::class)->receive($same->fresh(), null, 'system', []);
        $this->assertEquals(12, $this->available());
    }

    // ---------------------------------------------------------------- expiry

    public function test_expire_flags_old_unreceived_orders_without_inventing_quantities(): void
    {
        $old = $this->confirmedOrder($this->shop, now()->subHours(40));      // > 24 h + 12 h grace
        $recent = $this->confirmedOrder($this->shop, now()->subHours(30));   // inside the grace
        $received = $this->confirmedOrder($this->shop, now()->subHours(60));
        app(\App\Services\OrderReceiving::class)->receive($received->fresh(), null, 'system', []);
        $draft = Order::query()->create(['shop_id' => $this->shop->id, 'status' => OrderStatus::Draft, 'created_at' => now()->subDays(5)]);

        $this->artisan('orders:expire-transit')->expectsOutput('1 order(s) expired.')->assertSuccessful();

        $old->refresh();
        $this->assertSame('expired', $old->close_reason);
        $this->assertSame(OrderStatus::Confirmed, $old->status);
        $this->assertNull($old->received_at);
        $this->assertNull($old->items()->first()->qty_received);
        $this->assertSame(1, $old->events()->where('action', 'expired')->whereNull('user_id')->count());
        $this->assertSame('received', $received->fresh()->close_reason);
        $this->assertNull($draft->fresh()->close_reason);
        $this->assertNull($this->order->fresh()->close_reason);   // the fresh order of setUp
        $this->assertNull($recent->fresh()->close_reason);
    }

    public function test_an_expired_order_can_still_be_received_late(): void
    {
        $old = $this->confirmedOrder($this->shop, now()->subHours(40));
        $link = OrderLink::forUser($old, $this->aziz);
        $this->artisan('orders:expire-transit');
        $this->assertSame('expired', $old->fresh()->close_reason);

        Livewire::test(PublicDraft::class, ['token' => $link->token])->call('receive');

        $this->assertSame(OrderStatus::Fulfilled, $old->fresh()->status);
        $this->assertSame('received', $old->fresh()->close_reason);
    }

    public function test_the_commands_are_scheduled(): void
    {
        $expressions = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->mapWithKeys(fn ($e) => [$e->command => $e->expression]);

        $this->assertSame('0 * * * *', $expressions->first(fn ($x, $cmd) => str_contains($cmd, 'orders:expire-transit')));
        $this->assertSame('*/10 * * * *', $expressions->first(fn ($x, $cmd) => str_contains($cmd, 'orders:send-reminders')));
    }
}
