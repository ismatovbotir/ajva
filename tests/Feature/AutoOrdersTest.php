<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\SendOrderTelegram;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderRule;
use App\Models\Stock;
use App\Models\User;
use App\Services\AutoOrders;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsOrderWorld;
use Tests\TestCase;

/** orders:auto-generate: when a shop is due, what is skipped and why, and the draft + Telegram message it creates. */
class AutoOrdersTest extends TestCase
{
    use BuildsOrderWorld, RefreshDatabase;

    private Item $cola;

    private User $manager;

    private function manager(string $name, ?string $chat, \App\Models\Shop $shop): User
    {
        $user = User::factory()->operator()->create(['name' => $name, 'telegram_chat_id' => $chat]);
        $user->shops()->attach($shop->id);

        return $user;
    }

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');   // the assertions read the English texts
        $this->buildWorld();
        config(['services.telegram.bot_token' => 'BOT:TOKEN', 'app.url' => 'https://panel.test']);
        $this->telegramReplies(200, ['ok' => true, 'result' => []]);

        // Alpha sells 5 a day, has 4 left today (min 2, max 50): it needs an order; the warehouse has plenty.
        $this->cola = $this->item('Cola', $this->a, 4, 2, 50);
        $this->sellDaily($this->a, $this->cola, 5);
        $this->warehouseHas($this->cola, 1000);
        $this->defaultRule();
        OrderRule::query()->create(['shop_id' => $this->a->id]);
        $this->manager = $this->manager('Aziz', '4242', $this->a);
    }

    protected function tearDown(): void
    {
        $this->tearDownWorld();
        parent::tearDown();
    }

    /** Replaces any earlier Http fake (the first matching stub would win). */
    private function telegramReplies(int $status, array $body): void
    {
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.telegram.org/*' => Http::response($body, $status)]);
    }

    private function run_(): array
    {
        return app(AutoOrders::class)->run();
    }

    private function ruleOf(?int $shopId): OrderRule
    {
        return OrderRule::query()->where('shop_id', $shopId)->firstOrFail();
    }

    public function test_it_is_scheduled_every_five_minutes_without_overlapping(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'orders:auto-generate'));

        $this->assertNotNull($event);
        $this->assertSame('*/5 * * * *', $event->expression);
        $this->assertTrue($event->withoutOverlapping);
    }

    public function test_nothing_happens_before_the_run_time(): void
    {
        Carbon::setTestNow('2026-10-07 07:59:00');

        $this->assertSame([], $this->run_());
        $this->assertSame(0, Order::query()->count());
        $this->assertNull($this->ruleOf($this->a->id)->last_run_date);
    }

    public function test_a_disabled_shop_is_never_due_and_a_shop_can_switch_it_off(): void
    {
        $this->ruleOf($this->a->id)->update(['enabled' => false]);

        $this->assertSame([], $this->run_());
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_weekday_that_is_not_allowed_is_skipped(): void
    {
        $this->ruleOf(null)->update(['weekdays' => [1, 2]]);        // Monday, Tuesday; the 7th is a Wednesday

        $this->assertSame([], $this->run_());
        $this->assertSame(0, Order::query()->count());

        $this->ruleOf(null)->update(['weekdays' => [3]]);
        $this->assertSame('created', $this->run_()[0]['status']);
    }

    public function test_it_runs_once_a_day(): void
    {
        $this->assertSame('created', $this->run_()[0]['status']);
        $this->assertSame([], $this->run_());                       // same day, next tick
        $this->assertSame(1, Order::query()->count());

        Order::query()->get()->each(fn ($o) => $o->items()->delete() && $o->delete());   // the first draft is gone
        Carbon::setTestNow('2026-10-08 12:00:00');                  // the next day it is due again (the stock must be fresh)
        Stock::query()->where('item_id', $this->cola->id)->where('shop_id', $this->a->id)->where('stock_date', '2026-10-07')->update(['stock_date' => '2026-10-08']);
        $this->assertSame('created', $this->run_()[0]['status']);
    }

    public function test_the_claim_is_atomic(): void
    {
        $id = $this->ruleOf($this->a->id)->id;

        $this->assertTrue(OrderRule::claim($id, '2026-10-07'));
        $this->assertFalse(OrderRule::claim($id, '2026-10-07'));     // a second process loses
        $this->assertTrue(OrderRule::claim($id, '2026-10-08'));      // a new day is claimable again

        // A claimed day produces no draft.
        OrderRule::query()->where('id', $id)->update(['last_run_date' => '2026-10-08']);
        $this->assertSame([], $this->run_());
        $this->assertSame(0, Order::query()->count());
    }

    public function test_a_missing_stock_snapshot_waits_and_gives_up_after_four_hours(): void
    {
        Stock::query()->where('shop_id', $this->a->id)->where('stock_date', '2026-10-07')->delete();
        $this->ruleOf(null)->update(['run_time' => '10:00']);       // now is 12:00: two hours past

        $report = $this->run_();
        $this->assertSame('waiting_stock', $report[0]['status']);
        $rule = $this->ruleOf($this->a->id);
        $this->assertNull($rule->last_run_date);                    // not claimed: the next tick tries again
        $this->assertSame('waiting_stock', $rule->last_run_status);

        // The stock arrives: the next tick creates the draft.
        Stock::query()->create(['shop_id' => $this->a->id, 'item_id' => $this->cola->id, 'qty' => 4, 'stock_date' => '2026-10-07']);
        $this->assertSame('created', $this->run_()[0]['status']);
        Order::query()->get()->each(fn ($o) => $o->items()->delete() && $o->delete());
        OrderRule::query()->where('shop_id', $this->a->id)->update(['last_run_date' => null]);

        // No snapshot and more than 4 hours past the run time: the day is given up.
        Stock::query()->where('shop_id', $this->a->id)->where('stock_date', '2026-10-07')->delete();
        $this->ruleOf(null)->update(['run_time' => '07:00']);       // now is 12:00
        $this->assertSame('skipped_no_stock', $this->run_()[0]['status']);
        $this->assertSame('2026-10-07', substr((string) $this->ruleOf($this->a->id)->last_run_date, 0, 10));
        $this->assertSame([], $this->run_());
        $this->assertSame(0, Order::query()->count());
    }

    public function test_an_existing_draft_is_never_touched(): void
    {
        $draft = Order::query()->create(['shop_id' => $this->a->id, 'status' => OrderStatus::Draft]);
        $draft->items()->create(['item_id' => $this->cola->id, 'qty' => 7, 'suggested_qty' => 7]);

        $report = $this->run_();

        $this->assertSame('skipped_draft', $report[0]['status']);
        $this->assertSame(1, Order::query()->count());
        $this->assertEquals(7, $draft->items()->first()->qty);
        Http::assertNothingSent();
    }

    public function test_an_empty_result_creates_no_draft(): void
    {
        Stock::query()->where('item_id', $this->cola->id)->update(['qty' => 500]);   // plenty on the shelves

        $report = $this->run_();

        $this->assertSame('skipped_empty', $report[0]['status']);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame('skipped_empty', $this->ruleOf($this->a->id)->last_run_status);
        Http::assertNothingSent();
    }

    public function test_success_creates_a_draft_with_a_token_and_sends_telegram(): void
    {
        $report = $this->run_();

        $this->assertSame('created', $report[0]['status']);
        $order = Order::query()->firstOrFail();
        $this->assertSame(OrderStatus::Draft, $order->status);
        $this->assertSame(Order::ORIGIN_AUTO_SCHEDULE, $order->origin);
        $this->assertNull($order->created_by);
        $this->assertSame($this->a->id, $order->shop_id);
        $this->assertSame($this->wh->id, $order->warehouse_id);
        $link = \App\Models\OrderLink::query()->where('order_id', $order->id)->where('user_id', $this->manager->id)->firstOrFail();
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $link->token);
        $this->assertSame('sent', $order->telegram_status);
        $this->assertSame(1, $order->telegram_recipients_ok);
        $this->assertNotNull($order->telegram_sent_at);
        $event = $order->events()->firstOrFail();
        $this->assertSame(['system', 'created', null], [$event->channel, $event->action, $event->user_id]);
        $line = $order->items()->firstOrFail();
        $this->assertSame($this->cola->id, $line->item_id);
        $this->assertGreaterThan(0, (float) $line->qty);
        $this->assertLessThanOrEqual(150, (float) $line->qty);                // 3 x max
        $this->assertFalse((bool) $line->added_manually);

        Http::assertSentCount(1);
        Http::assertSent(function ($request) use ($link) {
            return $request->url() === 'https://api.telegram.org/botBOT:TOKEN/sendMessage'
                && $request['chat_id'] === '4242'
                && str_contains($request['text'], 'Alpha')
                && str_contains($request['text'], 'Cola')
                && ! str_contains($request['text'], 'BOT:TOKEN')
                && $request['reply_markup']['inline_keyboard'][0][0]['url'] === 'https://panel.test/o/'.$link->token;
        });

        $rule = $this->ruleOf($this->a->id);
        $this->assertSame('created', $rule->last_run_status);
        $this->assertSame('2026-10-07', substr((string) $rule->last_run_date, 0, 10));
        $this->assertStringContainsString('Telegram', (string) $rule->last_run_note);
    }

    public function test_a_shop_without_a_responsible_user_with_telegram_keeps_the_draft(): void
    {
        $this->manager->update(['telegram_chat_id' => null]);

        $report = $this->run_();

        $this->assertSame('created', $report[0]['status']);
        $this->assertSame(1, Order::query()->where('status', OrderStatus::Draft->value)->count());
        Http::assertNothingSent();
        $this->assertStringContainsString('no responsible user with Telegram', (string) $this->ruleOf($this->a->id)->last_run_note);
    }

    public function test_every_responsible_user_gets_the_message_and_users_without_a_chat_id_are_skipped(): void
    {
        $this->manager('Bekzod', '5555', $this->a);
        $this->manager('Dilnoza', null, $this->a);
        $this->manager('Other shop', '9999', $this->b);
        User::factory()->create(['telegram_chat_id' => '8888']);   // an admin is not assigned to the shop

        $this->assertSame('created', $this->run_()[0]['status']);

        Http::assertSentCount(2);
        $chats = [];
        Http::assertSent(function ($request) use (&$chats) {
            $chats[] = $request['chat_id'];

            return str_contains($request['text'], 'Cola');
        });
        sort($chats);
        $this->assertSame(['4242', '5555'], $chats);
        $this->assertStringContainsString('2 recipients', (string) $this->ruleOf($this->a->id)->last_run_note);
    }

    public function test_one_failing_recipient_does_not_stop_the_others_and_only_that_one_is_retried(): void
    {
        Queue::fake();
        $second = $this->manager('Bekzod', '5555', $this->a);
        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.telegram.org/*' => fn ($request) => $request['chat_id'] === '5555'
            ? Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)
            : Http::response(['ok' => true], 200)]);

        $this->assertSame('created', $this->run_()[0]['status']);

        Http::assertSentCount(2);
        $note = (string) $this->ruleOf($this->a->id)->last_run_note;
        $this->assertStringContainsString('sent to 1', $note);
        $this->assertStringContainsString('Bekzod', $note);
        $this->assertStringNotContainsString('BOT:TOKEN', $note);
        Queue::assertPushed(SendOrderTelegram::class, fn ($job) => $job->userIds === [$second->id]);
    }

    public function test_a_missing_bot_token_keeps_the_draft(): void
    {
        config(['services.telegram.bot_token' => '']);

        $this->assertSame('created', $this->run_()[0]['status']);
        $this->assertSame(1, Order::query()->count());
        Http::assertNothingSent();
    }

    public function test_a_telegram_failure_does_not_fail_the_order_and_queues_one_retry(): void
    {
        Queue::fake();
        $this->telegramReplies(403, ['ok' => false, 'description' => 'Forbidden: bot was blocked by the user']);

        $report = $this->run_();

        $this->assertSame('created', $report[0]['status']);
        $this->assertSame(1, Order::query()->count());
        Queue::assertPushed(SendOrderTelegram::class, 1);
        $this->assertStringContainsString('blocked', (string) $this->ruleOf($this->a->id)->last_run_note);
        $this->assertStringNotContainsString('BOT:TOKEN', (string) $this->ruleOf($this->a->id)->last_run_note);
    }

    public function test_the_retry_job_sends_the_link_later(): void
    {
        Queue::fake();
        $this->telegramReplies(429, ['ok' => false, 'description' => 'Too Many Requests']);
        $this->run_();
        $order = Order::query()->firstOrFail();

        $this->telegramReplies(200, ['ok' => true]);
        (new SendOrderTelegram($order->id))->handle(app(\App\Services\OrderTelegram::class));

        Http::assertSent(fn ($request) => $request['chat_id'] === '4242');
        $this->assertStringContainsString('link sent', (string) $this->ruleOf($this->a->id)->last_run_note);
    }

    public function test_quantities_are_capped_at_three_times_the_max_and_lines_without_history_are_left_out(): void
    {
        $big = $this->item('Big seller', $this->a, 1, 2, 10);        // sells 100 a day, max 10
        $this->sellDaily($this->a, $big, 100);
        $this->warehouseHas($big, 100000);
        $fresh = $this->item('Fresh', $this->a, 0, 2, 10, '2026-10-06');   // one day of history: no speed
        $this->warehouseHas($fresh, 100000);
        $old = $this->item('Old stock', $this->a, 0, 2, 10);
        $this->sellDaily($this->a, $old, 3);
        Stock::query()->where('item_id', $old->id)->where('stock_date', '2026-10-07')->delete();    // newest snapshot Sep 1: stale
        $this->warehouseHas($old, 100000);

        $report = $this->run_();

        $order = Order::query()->firstOrFail();
        $qty = $order->items()->pluck('qty', 'item_id');
        $this->assertEquals(30, $qty[$big->id]);                      // 3 x 10, not the ~300 the forecast asks for
        $this->assertFalse($qty->has($fresh->id));
        $this->assertFalse($qty->has($old->id));
        $this->assertStringContainsString('stale=1', (string) $order->warnings);
        $this->assertStringContainsString('none=1', (string) $order->warnings);
        $this->assertStringContainsString('capped=1', (string) $order->warnings);
        $this->assertStringContainsString('left out', (string) $report[0]['note']);
    }

    public function test_the_warehouse_stock_is_split_over_the_due_shops_most_urgent_first(): void
    {
        // The same item in both shops: Alpha has 4 left, Beta is out of stock; both sell 5 a day; the warehouse has 30.
        $milk = $this->item('Milk', $this->a, 4, 2, 50);
        Stock::query()->create(['shop_id' => $this->b->id, 'item_id' => $milk->id, 'qty' => 0, 'stock_date' => '2026-09-01']);
        Stock::query()->create(['shop_id' => $this->b->id, 'item_id' => $milk->id, 'qty' => 0, 'stock_date' => '2026-10-07']);
        \App\Models\ItemOrderRule::query()->create(['shop_id' => $this->b->id, 'item_id' => $milk->id, 'min' => 2, 'max' => 50]);
        $this->sellDaily($this->a, $milk, 5);
        $this->sellDaily($this->b, $milk, 5);
        $this->warehouseHas($milk, 30);

        $this->run_();

        $a = Order::query()->where('shop_id', $this->a->id)->firstOrFail()->items()->where('item_id', $milk->id)->firstOrFail();
        $b = Order::query()->where('shop_id', $this->b->id)->firstOrFail()->items()->where('item_id', $milk->id)->firstOrFail();
        $this->assertEquals((float) $b->suggested_qty, (float) $b->qty);        // the shop that is out of stock is served in full first
        $this->assertLessThan((float) $a->suggested_qty, (float) $a->qty);      // Alpha gets what is left, the short quantity stays visible
        $this->assertEquals(30, (float) $a->qty + (float) $b->qty);
        $this->assertStringContainsString('short', (string) $a->flags);
        $this->assertStringContainsString('out', (string) $b->flags);
    }

    public function test_open_confirmed_orders_count_as_in_transit(): void
    {
        $confirmed = Order::query()->create(['shop_id' => $this->a->id, 'status' => OrderStatus::Confirmed]);
        $confirmed->items()->create(['item_id' => $this->cola->id, 'qty' => 500, 'suggested_qty' => 500]);

        $report = $this->run_();

        $this->assertSame('skipped_empty', $report[0]['status']);   // 500 on the way covers the need
    }

    public function test_the_command_runs(): void
    {
        $this->artisan('orders:auto-generate')->expectsOutputToContain('Alpha: created')->assertSuccessful();
        $this->artisan('orders:auto-generate')->expectsOutput('Nothing to do.')->assertSuccessful();
    }
}
