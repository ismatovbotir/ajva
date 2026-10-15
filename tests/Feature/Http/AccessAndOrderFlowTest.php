<?php

namespace Tests\Feature\Http;

use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use App\Jobs\SyncOrderTo1C;
use App\Livewire\Orders\Index as OrdersIndex;
use App\Livewire\Orders\Show;
use App\Models\Item;
use App\Models\Monitor;
use App\Models\Order;
use App\Models\Shop;
use App\Models\User;
use App\Services\ReplenishmentOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Role x page matrix over HTTP, then the order flow end to end (prepare -> edit -> confirm -> lock -> 1C sync). */
class AccessAndOrderFlowTest extends TestCase
{
    use RefreshDatabase;

    // ---- access matrix ----------------------------------------------------------

    public function test_role_by_page_matrix(): void
    {
        Monitor::factory()->create();   // so /monitor has something to list

        // page => [admin, operator, monitor, guest]; 'R' = redirect, 200 = ok, 403 = forbidden.
        $matrix = [
            '/' => [200, 200, 'R:monitor', 'R:login'],
            '/orders' => [200, 200, 'R:monitor', 'R:login'],
            '/ordering' => [200, 403, 'R:monitor', 'R:login'],
            '/receipts' => [200, 200, 'R:monitor', 'R:login'],
            '/analytics' => [200, 200, 'R:monitor', 'R:login'],
            '/receipt-analytics' => [200, 200, 'R:monitor', 'R:login'],
            '/settings/users' => [200, 403, 'R:monitor', 'R:login'],
            '/ajva-ai' => [200, 403, 'R:monitor', 'R:login'],
        ];
        $users = [User::factory()->create(), User::factory()->operator()->create(), User::factory()->monitor()->create(), null];

        foreach ($matrix as $path => $expected) {
            foreach ($users as $i => $user) {
                $response = ($user ? $this->actingAs($user) : $this)->get($path);
                $want = $expected[$i];
                $label = "$path as role #$i";
                if (is_int($want)) {
                    $this->assertSame($want, $response->getStatusCode(), $label);
                } else {
                    $response->assertRedirect(route(explode(':', $want)[1]));
                }
                $this->flushSession();
                $this->app['auth']->forgetGuards();
            }
        }

        // /monitor itself: every signed-in role, not guests.
        foreach ($users as $i => $user) {
            if ($user) {
                $r = $this->actingAs($user)->get('/monitor');
                $this->assertContains($r->getStatusCode(), [200, 302], "monitor role #$i");
                $this->app['auth']->forgetGuards();
            }
        }
        $this->get('/monitor')->assertRedirect('/login');
    }

    public function test_login_form_post_flow_redirects_by_role_and_logout_ends_the_session(): void
    {
        $this->get('/login')->assertOk();

        $user = User::factory()->create(['email' => 'boss@example.com', 'password' => 'secret-pass']);
        Livewire::test(\App\Livewire\Auth\Login::class)
            ->set('email', 'boss@example.com')->set('password', 'secret-pass')->call('login')->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        // A signed-in user is bounced off the login page.
        $this->get('/login')->assertRedirect();

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/orders')->assertRedirect('/login');
    }

    // ---- order flow -------------------------------------------------------------

    private function plan(Shop $shop, Item $item, float $qty): array
    {
        return [
            'horizon' => 3,
            'warehouse_configured' => false,
            'shops' => [[
                'id' => $shop->id, 'name' => $shop->name,
                'rows' => [['item_id' => $item->id, 'need' => $qty, 'send' => $qty, 'estimated' => 1, 'min' => 5, 'speed' => 2.0, 'cover' => 0.5]],
            ]],
        ];
    }

    public function test_admin_prepares_a_draft_the_shops_operator_edits_and_confirms_and_it_locks_and_syncs(): void
    {
        config(['services.onec.orders_url' => 'https://onec.test/orders', 'services.onec.token' => 't']);
        $a = Shop::factory()->create(['name' => 'Alpha']);
        $b = Shop::factory()->create(['name' => 'Beta']);
        $cola = Item::factory()->create(['name' => 'Cola']);
        $admin = User::factory()->create();
        $opA = User::factory()->operator()->create();
        $opA->shops()->attach($a->id);
        $opB = User::factory()->operator()->create();
        $opB->shops()->attach($b->id);

        // 1. Admin prepares the draft (operators may not).
        $out = app(ReplenishmentOrders::class)->prepare($this->plan($a, $cola, 10), $a->id, $opA);
        $this->assertSame([], $out['created']);
        $this->assertSame(0, Order::query()->count());
        $out = app(ReplenishmentOrders::class)->prepare($this->plan($a, $cola, 10), $a->id, $admin);
        $this->assertCount(1, $out['created']);
        $draft = Order::query()->firstOrFail();
        $this->assertSame(OrderStatus::Draft, $draft->status);

        // 2. HTTP visibility: A's operator sees it, B's operator gets 404 and an empty list.
        $this->actingAs($opA)->get("/orders/{$draft->id}")->assertOk()->assertSee('Cola');
        $this->app['auth']->forgetGuards();
        $this->actingAs($opB)->get("/orders/{$draft->id}")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->actingAs($opB)->get('/orders')->assertOk()->assertDontSee($draft->number());
        Livewire::actingAs($opB)->test(Show::class, ['order' => $draft])->assertNotFound();
        Livewire::actingAs($opB)->test(OrdersIndex::class)->call('confirm', $draft->id)->assertNotFound();

        // 3. A's operator edits, then confirms; the queue worker (sync driver) pushes it to 1C.
        Http::fake(['onec.test/*' => Http::response(['ok' => true], 200)]);
        Livewire::actingAs($opA)->test(Show::class, ['order' => $draft])
            ->call('saveQty', $cola->id, '7')
            ->call('confirm');

        $order = $draft->fresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(OrderSyncStatus::Synced, $order->sync_status);
        $this->assertEquals(7, $order->items()->first()->qty);
        Http::assertSent(fn ($r) => $r->hasHeader('Idempotency-Key', $order->number()) && $r['lines'][0]['qty'] === 7.0);
        Http::assertSentCount(1);

        // 4. Locked for everybody, and a synced order is never re-sent (retry is refused, a re-run job is a no-op).
        foreach ([$opA, $admin] as $user) {
            Livewire::actingAs($user)->test(Show::class, ['order' => $order])
                ->call('saveQty', $cola->id, '1')->assertForbidden();
        }
        Livewire::actingAs($admin)->test(OrdersIndex::class)->call('retrySync', $order->id)->assertStatus(422);
        (new SyncOrderTo1C($order->id))->handle();
        Http::assertSentCount(1);
    }

    // ---- 1C sync job ------------------------------------------------------------

    private function confirmedOrder(): Order
    {
        $shop = Shop::factory()->create();
        $item = Item::factory()->create();
        $order = Order::query()->create([
            'shop_id' => $shop->id, 'status' => OrderStatus::Confirmed, 'sync_status' => OrderSyncStatus::Queued,
            'horizon_days' => 3, 'confirmed_at' => now(), 'queued_at' => now(),
        ]);
        $order->items()->create(['item_id' => $item->id, 'qty' => 4, 'suggested_qty' => 4]);

        return $order;
    }

    public function test_a_connection_timeout_is_recorded_and_rethrown_for_a_retry_then_marked_failed(): void
    {
        config(['services.onec.orders_url' => 'https://onec.test/orders']);
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $order = $this->confirmedOrder();
        $job = new SyncOrderTo1C($order->id);

        try {
            $job->handle();
            $this->fail('A timeout must throw so the queue retries it.');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('timed out', $e->getMessage());
        }
        $fresh = $order->fresh();
        $this->assertSame(OrderSyncStatus::Queued, $fresh->sync_status);
        $this->assertSame(1, $fresh->sync_attempts);
        $this->assertStringContainsString('timed out', $fresh->sync_error);

        $job->failed($e);
        $this->assertSame(OrderSyncStatus::Failed, $order->fresh()->sync_status);
    }

    public function test_a_late_failure_callback_never_downgrades_a_synced_order(): void
    {
        $order = $this->confirmedOrder();
        $order->forceFill(['sync_status' => OrderSyncStatus::Synced, 'synced_at' => now()])->save();

        (new SyncOrderTo1C($order->id))->failed(new \RuntimeException('late'));

        $this->assertSame(OrderSyncStatus::Synced, $order->fresh()->sync_status);
    }

    public function test_a_4xx_answer_also_fails_the_attempt_and_without_a_token_no_auth_header_is_sent(): void
    {
        config(['services.onec.orders_url' => 'https://onec.test/orders', 'services.onec.token' => '']);
        Http::fake(['onec.test/*' => Http::response('bad', 422)]);
        $order = $this->confirmedOrder();

        $this->expectException(\RuntimeException::class);
        try {
            (new SyncOrderTo1C($order->id))->handle();
        } finally {
            Http::assertSent(fn ($r) => ! $r->hasHeader('Authorization') && $r->hasHeader('Idempotency-Key', $order->number()));
            $this->assertStringContainsString('422', $order->fresh()->sync_error);
        }
    }

    public function test_an_empty_url_leaves_the_order_queued_and_dispatching_does_not_crash(): void
    {
        config(['services.onec.orders_url' => '']);
        Http::fake();
        $order = $this->confirmedOrder();

        (new SyncOrderTo1C($order->id))->handle();
        app(\App\Services\OrderSync::class)->dispatch($order);

        $this->assertSame(OrderSyncStatus::Queued, $order->fresh()->sync_status);
        $this->assertSame(0, $order->fresh()->sync_attempts);
        Http::assertNothingSent();
    }

    public function test_a_deleted_order_makes_the_job_a_silent_no_op(): void
    {
        Http::fake();
        (new SyncOrderTo1C(999999))->handle();
        Http::assertNothingSent();
    }

    public function test_confirming_pushes_exactly_one_job_per_confirm(): void
    {
        Queue::fake();
        $order = $this->confirmedOrder();
        $order->forceFill(['status' => OrderStatus::Draft, 'sync_status' => OrderSyncStatus::NotQueued])->save();

        Livewire::actingAs(User::factory()->create())->test(Show::class, ['order' => $order])->call('confirm');

        Queue::assertPushed(SyncOrderTo1C::class, 1);
    }
}
