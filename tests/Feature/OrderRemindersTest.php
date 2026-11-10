<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use App\Services\OrderReminders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** orders:send-reminders: unconfirmed drafts, failed 1C syncs, goods not received and the stock-out digest, each sent once. */
class OrderRemindersTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private User $aziz;

    private User $admin;

    private Order $draft;

    private bool $telegramOk = true;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Carbon::setTestNow('2026-10-07 12:00:00');
        config(['services.telegram.bot_token' => 'BOT:TOKEN', 'app.url' => 'https://panel.test']);
        Http::fake(['api.telegram.org/*' => fn () => $this->telegramOk
            ? Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)
            : Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);

        $this->shop = Shop::factory()->create(['name' => 'Alpha']);
        $this->aziz = User::factory()->operator()->create(['name' => 'Aziz', 'telegram_chat_id' => '111']);
        $this->aziz->shops()->attach($this->shop->id);
        $this->admin = User::factory()->create(['name' => 'Boss', 'telegram_chat_id' => '999']);
        $this->draft = Order::query()->create(['shop_id' => $this->shop->id, 'status' => OrderStatus::Draft]);
        $this->draft->items()->create(['item_id' => Item::factory()->create(['name' => 'Cola'])->id, 'qty' => 5]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function age(Order $order, float $hours): void
    {
        DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subMinutes((int) ($hours * 60))->toDateTimeString()]);
    }

    private function run_(): array
    {
        return app(OrderReminders::class)->run();
    }

    /** @return array<int, string> chat ids of the messages sent so far */
    private function chats(): array
    {
        return Http::recorded()->map(fn ($pair) => (string) $pair[0]->data()['chat_id'])->values()->all();
    }

    private function texts(string $chat): array
    {
        return Http::recorded()->filter(fn ($pair) => (string) $pair[0]->data()['chat_id'] === $chat)
            ->map(fn ($pair) => $pair[0]->data()['text'])->values()->all();
    }

    public function test_a_draft_is_reminded_at_two_and_four_hours_and_escalated_to_admins_at_six_once_each(): void
    {
        $this->age($this->draft, 1.5);
        $this->assertSame(['sent' => 0, 'failed' => 0], $this->run_());

        $this->age($this->draft, 2.5);
        $this->assertSame(1, $this->run_()['sent']);
        $this->assertSame(['111'], $this->chats());
        $this->assertStringContainsString('Alpha', $this->texts('111')[0]);
        Http::assertSent(fn (Request $r) => str_contains(json_encode($r->data()), 'https:\/\/panel.test\/o\/'));   // own link button
        $this->run_();
        $this->assertCount(1, $this->chats());   // never twice

        $this->age($this->draft, 4.5);
        $this->run_();
        $this->assertSame(['111', '111'], $this->chats());

        $this->age($this->draft, 7);
        $this->run_();
        $this->assertSame(['111', '111', '999'], $this->chats());
        $this->assertStringContainsString('ORD-', $this->texts('999')[0]);
        $this->run_();
        $this->assertCount(3, $this->chats());
    }

    public function test_a_late_start_sends_only_the_latest_step_not_a_burst(): void
    {
        $this->age($this->draft, 5);

        $this->run_();
        $this->run_();

        $this->assertSame(['111'], $this->chats());
        $this->assertSame(['draft_2'], DB::table('order_reminders')->pluck('kind')->all());
    }

    public function test_very_old_confirmed_empty_and_recipientless_cases_stay_quiet(): void
    {
        $old = Order::query()->create(['shop_id' => $this->shop->id, 'status' => OrderStatus::Draft]);
        $old->items()->create(['item_id' => Item::factory()->create()->id, 'qty' => 1]);
        $this->age($old, 100);
        $empty = Order::query()->create(['shop_id' => $this->shop->id, 'status' => OrderStatus::Draft]);
        $this->age($empty, 3);
        $this->age($this->draft, 0.5);
        $this->aziz->update(['telegram_chat_id' => null]);

        $this->assertSame(['sent' => 0, 'failed' => 0], $this->run_());
        $this->assertSame([], $this->chats());

        config(['services.telegram.bot_token' => '']);
        $this->age($this->draft, 3);
        $this->aziz->update(['telegram_chat_id' => '111']);
        $this->assertSame(['sent' => 0, 'failed' => 0], $this->run_());   // no token is not an error
        $this->assertSame(0, DB::table('order_reminders')->count());
    }

    public function test_a_failed_send_releases_the_claim_so_the_next_run_retries(): void
    {
        $this->age($this->draft, 3);
        $this->telegramOk = false;

        $this->assertSame(['sent' => 0, 'failed' => 1], $this->run_());
        $this->assertSame(0, DB::table('order_reminders')->count());

        $this->telegramOk = true;
        $this->assertSame(['sent' => 1, 'failed' => 0], $this->run_());
        $this->assertSame(1, DB::table('order_reminders')->count());
    }

    public function test_a_failed_1c_sync_alerts_the_admins_once(): void
    {
        $order = Order::query()->create(['shop_id' => $this->shop->id, 'status' => OrderStatus::Confirmed, 'confirmed_at' => now()->subMinutes(5), 'sync_status' => OrderSyncStatus::Failed]);
        $order->items()->create(['item_id' => Item::factory()->create()->id, 'qty' => 1]);
        $this->draft->delete();

        $this->run_();
        $this->run_();

        $this->assertSame(['999'], $this->chats());
        $this->assertStringContainsString($order->number(), $this->texts('999')[0]);
    }

    public function test_goods_not_received_remind_the_operator_after_the_delivery_day_and_the_admin_next_morning(): void
    {
        $this->draft->delete();
        // Confirmed this morning, deadline 20:00 today: nothing yet.
        $order = Order::query()->create(['shop_id' => $this->shop->id, 'status' => OrderStatus::Confirmed, 'confirmed_at' => '2026-10-07 10:00:00', 'sync_status' => OrderSyncStatus::Synced]);
        $order->items()->create(['item_id' => Item::factory()->create()->id, 'qty' => 1]);
        $this->run_();
        $this->assertSame([], $this->chats());

        // 21:00: the delivery day is over - the operator is reminded, the admin waits for the morning.
        Carbon::setTestNow('2026-10-07 21:00:00');
        $this->run_();
        $this->assertSame(['111'], $this->chats());

        Carbon::setTestNow('2026-10-08 09:10:00');
        $this->run_();
        $this->run_();
        $this->assertSame(['111', '999'], $this->chats());

        // Received: nothing more.
        $order->forceFill(['status' => OrderStatus::Fulfilled, 'received_at' => now()])->save();
        Carbon::setTestNow('2026-10-09 09:10:00');
        $this->run_();
        $this->assertCount(2, $this->chats());
    }

    public function test_an_unsynced_confirmed_order_is_not_reminded_as_not_received(): void
    {
        $this->draft->delete();
        $order = Order::query()->create(['shop_id' => $this->shop->id, 'status' => OrderStatus::Confirmed, 'confirmed_at' => '2026-10-06 10:00:00', 'sync_status' => OrderSyncStatus::Queued]);
        $order->items()->create(['item_id' => Item::factory()->create()->id, 'qty' => 1]);

        $this->run_();

        $this->assertSame([], $this->chats());
    }

    private function emptyShelf(string $name): Item
    {
        $item = Item::factory()->create(['name' => $name]);
        Stock::query()->create(['shop_id' => $this->shop->id, 'item_id' => $item->id, 'qty' => 0, 'stock_date' => now()->toDateString()]);
        ItemOrderRule::query()->create(['shop_id' => $this->shop->id, 'item_id' => $item->id, 'min' => 5, 'max' => 10]);

        return $item;
    }

    public function test_the_stock_out_digest_goes_once_a_day_to_the_operator_and_the_admin(): void
    {
        $this->draft->delete();
        $this->emptyShelf('Fanta');
        $this->emptyShelf('Sprite');

        Carbon::setTestNow('2026-10-07 07:00:00');
        $this->run_();
        $this->assertSame([], $this->chats());   // before 08:30

        Carbon::setTestNow('2026-10-07 08:40:00');
        $this->assertSame(2, $this->run_()['sent']);
        $this->run_();
        $this->assertEqualsCanonicalizing(['111', '999'], $this->chats());
        $operatorText = $this->texts('111')[0];
        $this->assertStringContainsString('Stock-out digest', $operatorText);
        $this->assertStringContainsString('Alpha: 2 items', $operatorText);
        $this->assertStringContainsString('Fanta', $operatorText);
        $this->assertStringContainsString('Sprite', $operatorText);

        // Next day it is sent again, and after the window it is not.
        Carbon::setTestNow('2026-10-08 13:00:00');
        $this->run_();
        $this->assertCount(2, $this->chats());
    }

    public function test_the_digest_is_skipped_when_nothing_is_out_and_does_not_rebuild_on_every_run(): void
    {
        $this->draft->delete();
        Carbon::setTestNow('2026-10-07 08:40:00');

        $this->assertSame(['sent' => 0, 'failed' => 0], $this->run_());
        $this->assertSame([], $this->chats());
        $this->assertSame(2, DB::table('order_reminders')->where('kind', 'stockout_20261007')->count());   // handled marks
    }

    public function test_the_command_runs_and_the_manual_reminder_goes_to_the_responsible_people(): void
    {
        $this->artisan('orders:send-reminders')->expectsOutput('Reminders sent: 0, failed: 0.')->assertSuccessful();

        $res = app(OrderReminders::class)->sendNow($this->draft);
        $this->assertSame(['sent' => 1, 'failed' => 0, 'recipients' => 1], $res);
        $this->assertSame(['111'], $this->chats());
        $this->assertSame(0, DB::table('order_reminders')->count());   // manual reminders are not part of the idempotent log
    }
}
