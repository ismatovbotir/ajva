<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use App\Livewire\Orders\Unconfirmed;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderLink;
use App\Models\Shop;
use App\Models\User;
use App\Services\UnconfirmedOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** The "Not confirmed orders" report: drafts, goods not received, stuck 1C syncs; scoped by shop access. */
class UnconfirmedOrdersTest extends TestCase
{
    use RefreshDatabase;

    private Shop $alpha;

    private Shop $beta;

    private User $admin;

    private User $aziz;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->alpha = Shop::factory()->create(['name' => 'Alpha']);
        $this->beta = Shop::factory()->create(['name' => 'Beta']);
        $this->admin = User::factory()->create();
        $this->aziz = User::factory()->operator()->create(['name' => 'Aziz', 'telegram_chat_id' => '111']);
        $this->aziz->shops()->attach($this->alpha->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function order(Shop $shop, OrderStatus $status, array $attrs = [], ?float $ageHours = null): Order
    {
        $order = Order::query()->create(['shop_id' => $shop->id, 'status' => $status] + $attrs);
        $order->items()->create(['item_id' => Item::factory()->create()->id, 'qty' => 1]);
        if ($ageHours !== null) {
            DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subMinutes((int) ($ageHours * 60))->toDateTimeString()]);
        }

        return $order;
    }

    private function confirmed(Shop $shop, string $at, array $attrs = []): Order
    {
        return $this->order($shop, OrderStatus::Confirmed, array_merge(['confirmed_at' => $at, 'sync_status' => OrderSyncStatus::Synced], $attrs));
    }

    public function test_rows_cover_drafts_receipts_and_sync_problems_oldest_first_with_severity(): void
    {
        $fresh = $this->order($this->alpha, OrderStatus::Draft, [], 1);
        $amber = $this->order($this->alpha, OrderStatus::Draft, [], 3);
        $red = $this->order($this->beta, OrderStatus::Draft, [], 30);
        $onTheWay = $this->confirmed($this->alpha, '2026-10-07 09:00:00');
        $overdue = $this->confirmed($this->alpha, '2026-10-06 10:00:00');
        $failed = $this->confirmed($this->beta, '2026-10-07 11:00:00', ['sync_status' => OrderSyncStatus::Failed]);
        $this->order($this->alpha, OrderStatus::Fulfilled);
        $this->order($this->alpha, OrderStatus::Cancelled);
        $this->confirmed($this->alpha, '2026-10-06 10:00:00', ['received_at' => now()]);

        $rows = app(UnconfirmedOrders::class)->rows()->keyBy(fn ($r) => $r['order']->id);

        $this->assertSame(6, $rows->count());
        $this->assertSame([$red->id, $overdue->id], app(UnconfirmedOrders::class)->rows()->take(2)->map(fn ($r) => $r['order']->id)->all());   // oldest first
        $this->assertSame('none', $rows[$fresh->id]['severity']);
        $this->assertSame('amber', $rows[$amber->id]['severity']);
        $this->assertSame('red', $rows[$red->id]['severity']);
        $this->assertSame(['draft'], $rows[$red->id]['kinds']);
        $this->assertSame('none', $rows[$onTheWay->id]['severity']);
        $this->assertTrue($rows[$overdue->id]['overdue']);
        $this->assertSame('red', $rows[$overdue->id]['severity']);
        $this->assertSame(['receipt', 'sync'], $rows[$failed->id]['kinds']);
        $this->assertSame('red', $rows[$failed->id]['severity']);
    }

    public function test_filters_by_shop_and_type(): void
    {
        $this->order($this->alpha, OrderStatus::Draft, [], 3);
        $this->confirmed($this->alpha, '2026-10-06 10:00:00');
        $this->confirmed($this->beta, '2026-10-07 11:00:00', ['sync_status' => OrderSyncStatus::Failed]);
        $service = app(UnconfirmedOrders::class);

        $this->assertCount(3, $service->rows());
        $this->assertCount(2, $service->rows($this->alpha->id));
        $this->assertCount(1, $service->rows(null, 'draft'));
        $this->assertCount(2, $service->rows(null, 'receipt'));
        $this->assertCount(1, $service->rows(null, 'sync'));
    }

    public function test_a_queued_sync_is_only_listed_as_a_problem_after_fifteen_minutes(): void
    {
        $recent = $this->confirmed($this->alpha, '2026-10-07 11:55:00', ['sync_status' => OrderSyncStatus::Queued, 'queued_at' => now()->subMinutes(5)]);
        $stale = $this->confirmed($this->alpha, '2026-10-07 11:00:00', ['sync_status' => OrderSyncStatus::Queued, 'queued_at' => now()->subMinutes(60)]);

        $rows = app(UnconfirmedOrders::class)->rows(null, 'sync');

        $this->assertSame([$stale->id], $rows->map(fn ($r) => $r['order']->id)->all());
        $this->assertSame('amber', $rows->first()['severity']);
        $this->assertNotNull($recent);
    }

    public function test_an_operator_sees_only_his_shops_and_cannot_pick_another(): void
    {
        $mine = $this->order($this->alpha, OrderStatus::Draft, [], 3);
        $theirs = $this->order($this->beta, OrderStatus::Draft, [], 3);

        $this->actingAs($this->aziz)->get(route('monitoring.unconfirmed-orders'))->assertOk()
            ->assertSee($mine->number())->assertDontSee($theirs->number());

        Livewire::actingAs($this->aziz)->test(Unconfirmed::class)->set('shop', (string) $this->beta->id)
            ->assertSee($mine->number())->assertDontSee($theirs->number());
    }

    public function test_an_operator_without_shops_sees_the_hint_and_other_roles_are_refused(): void
    {
        $lonely = User::factory()->operator()->create();
        $this->order($this->alpha, OrderStatus::Draft, [], 3);

        $this->actingAs($lonely)->get(route('monitoring.unconfirmed-orders'))->assertOk()->assertSee('No shops are assigned to you');
        $this->actingAs(User::factory()->monitor()->create())->get(route('monitoring.unconfirmed-orders'))->assertRedirect();   // the monitor role has its own home
        auth()->logout();
        $this->get(route('monitoring.unconfirmed-orders'))->assertRedirect(route('login'));
    }

    public function test_the_page_shows_age_link_opened_and_telegram_state_and_the_admin_filters(): void
    {
        $draft = $this->order($this->alpha, OrderStatus::Draft, ['telegram_status' => 'sent', 'telegram_recipients_ok' => 1], 3);
        $this->order($this->beta, OrderStatus::Draft, [], 3);
        OrderLink::forUser($draft, $this->aziz)->forceFill(['last_used_at' => now()->subHour()])->save();

        $page = Livewire::actingAs($this->admin)->test(Unconfirmed::class)
            ->assertSee('Aziz')->assertSee('3 h 0 min')->assertSee('Opened 07.10 11:00')->assertSeeHtml('data-telegram-badge="sent"')
            ->assertSeeHtml('data-severity="amber"')->assertSee('Send reminder now');

        $page->set('shop', (string) $this->alpha->id)->assertSee($draft->number());
        $page->set('type', 'sync')->assertSee('Nothing is waiting');
    }

    public function test_only_an_admin_can_send_a_reminder_now(): void
    {
        config(['services.telegram.bot_token' => 'BOT:TOKEN', 'app.url' => 'https://panel.test']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        $draft = $this->order($this->alpha, OrderStatus::Draft, [], 3);

        Livewire::actingAs($this->aziz)->test(Unconfirmed::class)->call('sendReminder', $draft->id)->assertForbidden();
        Http::assertNothingSent();

        Livewire::actingAs($this->admin)->test(Unconfirmed::class)->call('sendReminder', $draft->id)->assertSee('Reminder sent to 1 people.');
        Http::assertSentCount(1);
    }
}
