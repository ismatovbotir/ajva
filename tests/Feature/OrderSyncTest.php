<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use App\Jobs\SyncOrderTo1C;
use App\Livewire\Orders\Index;
use App\Models\Item;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class OrderSyncTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.onec.orders_url' => 'https://onec.test/orders', 'services.onec.token' => 'secret']);
        $shop = Shop::factory()->create();
        $this->item = Item::factory()->create();
        $this->order = Order::query()->create(['shop_id' => $shop->id, 'status' => OrderStatus::PendingApproval, 'horizon_days' => 3]);
        $this->order->items()->create(['item_id' => $this->item->id, 'qty' => 7, 'suggested_qty' => 9, 'horizon_days' => 3]);
    }

    private function admin(): User
    {
        return User::factory()->create();
    }

    public function test_confirming_queues_the_sync(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin())->test(Index::class)->call('confirm', $this->order->id);

        $order = $this->order->fresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(OrderSyncStatus::Queued, $order->sync_status);
        $this->assertNotNull($order->queued_at);
        Queue::assertPushed(SyncOrderTo1C::class, fn ($job) => $job->orderId === $this->order->id);
    }

    public function test_http_200_marks_the_order_synced_and_sends_the_documented_payload(): void
    {
        Http::fake(['onec.test/*' => Http::response(['ok' => true], 200)]);

        Livewire::actingAs($this->admin())->test(Index::class)->call('confirm', $this->order->id);

        $order = $this->order->fresh();
        $this->assertSame(OrderSyncStatus::Synced, $order->sync_status);
        $this->assertNotNull($order->synced_at);
        $this->assertSame(1, $order->sync_attempts);
        $this->assertNull($order->sync_error);
        Http::assertSent(function (Request $request) use ($order) {
            return $request->url() === 'https://onec.test/orders'
                && $request->hasHeader('Authorization', 'Bearer secret')
                && $request->hasHeader('Idempotency-Key', $order->number())
                && $request['id'] === $order->id
                && $request['number'] === $order->number()
                && $request['shop_id'] === $order->shop_id
                && $request['horizon_days'] === 3
                && $request['lines'] === [['item_id' => $this->item->id, 'qty' => 7.0]];
        });
    }

    public function test_an_error_answer_is_retried_then_failed_and_can_be_requeued(): void
    {
        Http::fake(['onec.test/*' => Http::sequence()->push('boom', 500)->push('', 200)]);   // 1C recovers later

        // Under a real worker the job throws, the queue retries it with backoff and calls failed() after the last try.
        $this->order->forceFill(['status' => OrderStatus::Confirmed, 'sync_status' => OrderSyncStatus::Queued])->save();
        $job = new SyncOrderTo1C($this->order->id);
        try {
            $job->handle();
            $this->fail('A 500 answer must throw so the queue retries.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('500', $e->getMessage());
        }
        $order = $this->order->fresh();
        $this->assertSame(OrderSyncStatus::Queued, $order->sync_status);   // still being retried
        $this->assertSame(1, $order->sync_attempts);
        $this->assertStringContainsString('500', $order->sync_error);

        $this->assertSame(5, $job->tries);
        $this->assertSame([30, 120, 300, 900], $job->backoff());
        $this->assertLessThan(config('queue.connections.database.retry_after'), $job->timeout);

        $job->failed($e);
        $this->assertSame(OrderSyncStatus::Failed, $this->order->fresh()->sync_status);

        // The admin re-queues it; 1C is fine now.
        Livewire::actingAs($this->admin())->test(Index::class)->call('retrySync', $this->order->id);
        $order = $this->order->fresh();
        $this->assertSame(OrderSyncStatus::Synced, $order->sync_status);
        $this->assertNull($order->sync_error);
    }

    public function test_without_an_url_the_order_stays_queued_and_nothing_is_sent(): void
    {
        config(['services.onec.orders_url' => '']);
        Http::fake();

        $component = Livewire::actingAs($this->admin())->test(Index::class)->call('confirm', $this->order->id);

        $this->assertSame(OrderSyncStatus::Queued, $this->order->fresh()->sync_status);
        Http::assertNothingSent();
        $component->assertSee('ONEC_ORDERS_URL');
    }

    public function test_a_synced_order_is_never_sent_again(): void
    {
        Http::fake();
        $this->order->forceFill(['status' => OrderStatus::Confirmed, 'sync_status' => OrderSyncStatus::Synced, 'synced_at' => now()])->save();

        (new SyncOrderTo1C($this->order->id))->handle();
        Http::assertNothingSent();

        Livewire::actingAs($this->admin())->test(Index::class)->call('retrySync', $this->order->id)->assertStatus(422);
        Http::assertNothingSent();
    }

    public function test_only_admins_confirm_or_retry_and_operators_are_scoped_to_their_shops(): void
    {
        Http::fake();
        $op = User::factory()->operator()->create();

        // Not their shop: 404; their own shop: still not allowed to confirm (403).
        Livewire::actingAs($op)->test(Index::class)->call('confirm', $this->order->id)->assertNotFound();
        $op->shops()->attach($this->order->shop_id);
        Livewire::actingAs($op)->test(Index::class)->call('confirm', $this->order->id)->assertForbidden();
        Livewire::actingAs($op)->test(Index::class)->call('retrySync', $this->order->id)->assertForbidden();

        $this->assertSame(OrderStatus::PendingApproval, $this->order->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_the_orders_page_shows_the_sync_state_error_and_retry_button(): void
    {
        $this->order->forceFill([
            'status' => OrderStatus::Confirmed, 'sync_status' => OrderSyncStatus::Failed,
            'sync_attempts' => 5, 'sync_error' => '1C answered HTTP 500: boom',
        ])->save();

        Livewire::actingAs($this->admin())->test(Index::class)
            ->assertSee(__('Failed'))->assertSee('1C answered HTTP 500')->assertSee(__('Retry sync'));

        $op = User::factory()->operator()->create();
        $op->shops()->attach($this->order->shop_id);
        Livewire::actingAs($op)->test(Index::class)->assertSee(__('Failed'))->assertDontSee(__('Retry sync'));
    }
}
