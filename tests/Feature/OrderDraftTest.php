<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use App\Jobs\SyncOrderTo1C;
use App\Livewire\Orders\Show;
use App\Models\Item;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** The editable draft: who may edit / add / remove / confirm / delete, and the lock after confirmation. */
class OrderDraftTest extends TestCase
{
    use RefreshDatabase;

    private Shop $a;

    private Shop $b;

    private Order $draft;

    private Item $cola;

    private User $opA;

    private User $opB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->a = Shop::factory()->create(['name' => 'Alpha']);
        $this->b = Shop::factory()->create(['name' => 'Beta']);
        $this->cola = Item::factory()->create(['name' => 'Cola']);
        $this->draft = Order::query()->create(['shop_id' => $this->a->id, 'status' => OrderStatus::Draft, 'horizon_days' => 3]);
        $this->draft->items()->create(['item_id' => $this->cola->id, 'qty' => 10, 'suggested_qty' => 10, 'est' => 0, 'min' => 5]);
        $this->opA = User::factory()->operator()->create();
        $this->opA->shops()->attach($this->a->id);
        $this->opB = User::factory()->operator()->create();
        $this->opB->shops()->attach($this->b->id);
    }

    public function test_the_shops_person_edits_adds_and_removes_lines_of_a_draft(): void
    {
        $water = Item::factory()->create(['name' => 'Water bottle']);

        $component = Livewire::actingAs($this->opA)->test(Show::class, ['order' => $this->draft])
            ->assertSee('Cola')
            ->call('saveQty', $this->cola->id, '4')
            ->set('search', 'Water')->assertSee('Water bottle')
            ->set('addQty', '6')->call('addItem', $water->id);

        $items = $this->draft->items()->get()->keyBy('item_id');
        $this->assertEquals(4, $items[$this->cola->id]->qty);
        $this->assertEquals(10, $items[$this->cola->id]->suggested_qty);       // the suggestion stays
        $this->assertEquals(6, $items[$water->id]->qty);
        $this->assertTrue($items[$water->id]->added_manually);
        $this->assertNull($items[$water->id]->suggested_qty);

        $component->call('removeItem', $this->cola->id);
        $this->assertSame([$water->id], $this->draft->items()->pluck('item_id')->all());
    }

    public function test_quantities_must_be_positive_and_sane(): void
    {
        $component = Livewire::actingAs($this->opA)->test(Show::class, ['order' => $this->draft]);

        foreach (['0', '-3', 'abc', '99999999'] as $bad) {
            $component->call('saveQty', $this->cola->id, $bad)->assertHasErrors('qty');
        }
        $this->assertEquals(10, $this->draft->items()->first()->qty);

        $component->call('saveQty', $this->cola->id, '2.5')->assertHasNoErrors();
        $this->assertEquals(2.5, $this->draft->items()->first()->qty);
    }

    public function test_another_shops_operator_cannot_see_or_edit_the_draft(): void
    {
        $this->actingAs($this->opB)->get('/orders/'.$this->draft->id)->assertNotFound();
        $this->actingAs($this->opB)->get('/orders')->assertOk()->assertDontSee($this->draft->number());

        Livewire::actingAs($this->opB)->test(Show::class, ['order' => $this->draft])->assertNotFound();
    }

    public function test_confirming_locks_the_order_and_queues_the_sync(): void
    {
        Queue::fake();

        Livewire::actingAs($this->opA)->test(Show::class, ['order' => $this->draft])->call('confirm');

        $order = $this->draft->fresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(OrderSyncStatus::Queued, $order->sync_status);
        $this->assertNotNull($order->confirmed_at);
        $this->assertSame($this->opA->id, $order->confirmed_by);
        Queue::assertPushed(SyncOrderTo1C::class, fn ($job) => $job->orderId === $order->id);

        // Locked: no edits, adds, removals, second confirm or delete - not even for an admin.
        foreach ([$this->opA, User::factory()->create()] as $user) {
            $page = fn () => Livewire::actingAs($user)->test(Show::class, ['order' => $order]);
            $page()->call('saveQty', $this->cola->id, '1')->assertForbidden();
            $page()->call('removeItem', $this->cola->id)->assertForbidden();
            $page()->call('addItem', Item::factory()->create()->id)->assertForbidden();
            $page()->call('confirm')->assertForbidden();
            $page()->call('delete')->assertForbidden();
        }
        $this->assertEquals(10, $order->items()->first()->qty);
        $this->assertSame(1, $order->items()->count());
    }

    public function test_an_empty_draft_cannot_be_confirmed(): void
    {
        Queue::fake();
        $this->draft->items()->delete();

        Livewire::actingAs($this->opA)->test(Show::class, ['order' => $this->draft])->call('confirm')->assertStatus(422);

        $this->assertSame(OrderStatus::Draft, $this->draft->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_an_admin_edits_confirms_and_deletes_any_shops_draft_but_an_operator_cannot_delete(): void
    {
        $admin = User::factory()->create();

        Livewire::actingAs($this->opA)->test(Show::class, ['order' => $this->draft])->call('delete')->assertForbidden();
        $this->assertNotNull($this->draft->fresh());

        Livewire::actingAs($admin)->test(Show::class, ['order' => $this->draft])
            ->call('saveQty', $this->cola->id, '8')
            ->call('delete')->assertRedirect(route('orders.index'));

        $this->assertNull(Order::query()->find($this->draft->id));
        $this->assertSame(0, \App\Models\OrderItem::query()->count());
    }

    public function test_the_orders_list_is_scoped_and_links_to_the_draft(): void
    {
        $this->actingAs($this->opA)->get('/orders')->assertOk()->assertSee($this->draft->number())->assertSee('/orders/'.$this->draft->id);
        $this->actingAs($this->opA)->get('/orders/'.$this->draft->id)->assertOk()->assertSee('Cola');
    }
}
