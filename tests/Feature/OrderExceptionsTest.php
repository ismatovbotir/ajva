<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use App\Jobs\SyncOrderTo1C;
use App\Livewire\Orders\Index;
use App\Livewire\Orders\PublicDraft;
use App\Livewire\Orders\Show;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderLink;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\User;
use App\Services\OrderExceptions;
use App\Services\OrderReminders;
use App\Services\UnconfirmedOrders;
use App\Support\InTransit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Exception rules at confirm time and the admin approval before an order is sent to 1C. */
class OrderExceptionsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Item $cola;

    private Item $water;

    private Order $draft;

    private User $aziz;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        config(['services.telegram.bot_token' => 'BOT:TOKEN', 'app.url' => 'https://panel.test']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)]);

        $this->shop = Shop::factory()->create(['name' => 'Alpha']);
        $this->cola = Item::factory()->create(['name' => 'Cola']);
        $this->water = Item::factory()->create(['name' => 'Water']);
        ItemOrderRule::query()->create(['item_id' => $this->cola->id, 'shop_id' => $this->shop->id, 'min' => 2, 'max' => 10]);
        $this->aziz = User::factory()->operator()->create(['name' => 'Aziz', 'telegram_chat_id' => '111']);
        $this->aziz->shops()->attach($this->shop->id);
        $this->admin = User::factory()->create(['name' => 'Boss', 'telegram_chat_id' => '999']);
        $this->draft = $this->order();
    }

    private function order(?Shop $shop = null): Order
    {
        return Order::query()->create(['shop_id' => ($shop ?? $this->shop)->id, 'status' => OrderStatus::Draft, 'horizon_days' => 3]);
    }

    private function line(Order $order, Item $item, float $qty, ?float $suggested = null, bool $manual = false): void
    {
        $order->items()->create(['item_id' => $item->id, 'qty' => $qty, 'suggested_qty' => $suggested, 'added_manually' => $manual]);
    }

    /** @return array<int, string> */
    private function codes(?Order $order = null): array
    {
        return array_column(app(OrderExceptions::class)->evaluate($order ?? $this->draft->fresh()), 'code');
    }

    private function pending(): Order
    {
        $this->line($this->draft, $this->cola, 31, 10);   // 31 > 3 x max 10
        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $this->draft])->call('confirm');

        return $this->draft->fresh();
    }

    private function telegramTexts(string $chat): array
    {
        return Http::recorded()->filter(fn ($pair) => (string) $pair[0]->data()['chat_id'] === $chat)
            ->map(fn ($pair) => $pair[0]->data()['text'])->values()->all();
    }

    // ------------------------------------------------------------------ rules

    public function test_a_line_above_three_times_the_max_rule_is_flagged_but_exactly_three_times_is_not(): void
    {
        $this->line($this->draft, $this->cola, 30, 30);
        $this->assertSame([], $this->codes());

        $this->draft->items()->update(['qty' => 30.5]);
        $this->assertContains('line_over_max', $this->codes());
    }

    public function test_a_line_without_a_max_rule_is_not_checked_against_max(): void
    {
        $this->line($this->draft, $this->water, 500, 500);   // no rule for water

        $this->assertNotContains('line_over_max', $this->codes());
    }

    public function test_a_system_line_above_twice_its_suggestion_is_flagged(): void
    {
        $this->line($this->draft, $this->water, 20, 10);
        $this->assertSame([], $this->codes());

        $this->draft->items()->update(['qty' => 21]);
        $this->assertSame(['line_over_system'], $this->codes());
    }

    public function test_a_low_line_is_flagged_only_for_an_abc_class_a_item(): void
    {
        $other = Item::factory()->create(['name' => 'Chips']);
        foreach ([$this->cola, $other] as $item) {
            ItemOrderRule::query()->updateOrCreate(['item_id' => $item->id, 'shop_id' => $this->shop->id], ['min' => 1, 'max' => 50]);
        }
        // Cola is ~95 % of the revenue (class A); chips are the small rest (class C).
        foreach ([[$this->cola, 950], [$other, 50]] as [$item, $total]) {
            $receipt = Receipt::factory()->create(['shop_id' => $this->shop->id, 'active' => true, 'sell' => true, 'created_at' => now()->subDays(2)]);
            ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'qty' => 5, 'total' => $total, 'storno' => false]);
        }
        $this->line($this->draft, $this->cola, 3, 10);    // exactly 0.3 x: fine
        $this->line($this->draft, $other, 1, 10);         // 0.1 x but class C
        $this->assertSame([], $this->codes());

        $this->draft->items()->where('item_id', $this->cola->id)->update(['qty' => 2]);
        $this->assertSame(['line_under_system'], $this->codes());
    }

    public function test_a_low_line_is_skipped_without_sales_history(): void
    {
        $this->line($this->draft, $this->cola, 1, 10);   // would be class A, but there are no sales: no ABC data

        $this->assertSame([], $this->codes());
    }

    public function test_a_manual_line_without_a_rule_is_flagged_only_when_the_shop_has_rules(): void
    {
        $this->line($this->draft, $this->water, 4, null, true);
        $this->assertSame(['manual_no_rule'], $this->codes());

        $this->draft->items()->update(['qty' => 4]);
        $this->draft->items()->where('item_id', $this->water->id)->delete();
        $this->line($this->draft, $this->cola, 4, null, true);   // has a rule
        $this->assertSame([], $this->codes());

        // A shop with no rules at all (not synced yet): nothing to compare with, no noise.
        $bare = $this->order(Shop::factory()->create(['name' => 'Bare']));
        $this->line($bare, $this->water, 4, null, true);
        $this->assertSame([], $this->codes($bare));
    }

    public function test_the_order_total_is_compared_with_the_shop_average_only_with_enough_history(): void
    {
        $this->line($this->draft, $this->water, 16, 16);
        $history = function (int $count, ?Carbon $at = null) {
            for ($i = 0; $i < $count; $i++) {
                $o = Order::query()->create(['shop_id' => $this->shop->id, 'status' => OrderStatus::Fulfilled, 'confirmed_at' => $at ?? now()->subDays(3 + $i)]);
                $o->items()->create(['item_id' => $this->water->id, 'qty' => 10]);
            }
        };

        $history(2);
        $this->assertSame([], $this->codes(), 'two earlier orders are not enough');

        $history(1);   // three orders of 10 pieces: average 10, limit 15
        $this->assertSame(['total_high'], $this->codes());

        $this->draft->items()->update(['qty' => 15, 'suggested_qty' => 15]);
        $this->assertSame([], $this->codes(), '1.5 x the average exactly is fine');
    }

    public function test_orders_older_than_four_weeks_do_not_count_for_the_average(): void
    {
        $this->line($this->draft, $this->water, 100, 100);
        for ($i = 0; $i < 4; $i++) {
            $o = Order::query()->create(['shop_id' => $this->shop->id, 'status' => OrderStatus::Confirmed, 'confirmed_at' => now()->subDays(40 + $i)]);
            $o->items()->create(['item_id' => $this->water->id, 'qty' => 10]);
        }

        $this->assertSame([], $this->codes());
    }

    public function test_an_insane_quantity_is_flagged(): void
    {
        $this->line($this->draft, $this->water, 100001, 100001);

        $this->assertContains('qty_insane', $this->codes());
    }

    public function test_the_whole_check_can_be_switched_off(): void
    {
        $this->line($this->draft, $this->cola, 500, 10);
        $this->assertNotEmpty($this->codes());

        config(['inventory.order_exceptions' => false]);

        $this->assertSame([], $this->codes());
    }

    // ------------------------------------------------------------------ behaviour

    public function test_an_order_without_exceptions_is_confirmed_and_synced_as_before(): void
    {
        Queue::fake();
        $this->line($this->draft, $this->cola, 10, 10);

        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $this->draft])->call('confirm');

        $order = $this->draft->fresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame(OrderSyncStatus::Queued, $order->sync_status);
        $this->assertNull($order->review_status);
        Queue::assertPushed(SyncOrderTo1C::class);
    }

    public function test_an_operators_order_with_exceptions_waits_for_an_admin_and_is_not_sent_or_in_transit(): void
    {
        Queue::fake();

        $order = $this->pending();

        $this->assertSame(OrderStatus::PendingApproval, $order->status);
        $this->assertSame('pending', $order->review_status);
        $this->assertSame(OrderSyncStatus::NotQueued, $order->sync_status);
        $this->assertNull($order->confirmed_at);
        $this->assertSame('line_over_max', $order->review_exceptions[0]['code']);
        Queue::assertNothingPushed();
        $this->assertSame(0, InTransit::apply(DB::table('orders as o'))->count());
        $this->assertSame(OrderEvent::SENT_FOR_APPROVAL, $order->events()->latest('id')->first()->action);

        // Locked: no edits while it waits.
        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $order])->assertSee('Sent for approval')->assertSee('more than 3 x the maximum');
        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $order])->call('saveQty', $this->cola->id, '5')->assertForbidden();
        $this->assertEquals(31, $order->items()->first()->qty);

        // The admins hear about it once, even if the reminders run again.
        $this->assertCount(1, $this->telegramTexts('999'));
        $this->assertStringContainsString('waiting for your approval', $this->telegramTexts('999')[0]);
        app(OrderReminders::class)->run();
        $this->assertCount(1, $this->telegramTexts('999'));
    }

    public function test_an_older_pending_approval_order_without_review_still_counts_as_in_transit(): void
    {
        $old = Order::query()->create(['shop_id' => $this->shop->id, 'status' => OrderStatus::PendingApproval]);

        $this->assertSame(1, InTransit::apply(DB::table('orders as o'))->where('o.id', $old->id)->count());
    }

    public function test_an_admin_approves_and_the_order_is_synced_and_in_transit(): void
    {
        Queue::fake();
        $order = $this->pending();

        Livewire::actingAs($this->admin)->test(Show::class, ['order' => $order])->call('approve');

        $order = $order->fresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame('approved', $order->review_status);
        $this->assertSame($this->admin->id, $order->reviewed_by);
        $this->assertNotNull($order->reviewed_at);
        $this->assertSame(OrderSyncStatus::Queued, $order->sync_status);
        Queue::assertPushed(SyncOrderTo1C::class, fn ($job) => $job->orderId === $order->id);
        $this->assertSame(1, InTransit::apply(DB::table('orders as o'))->count());
        $this->assertEqualsCanonicalizing([OrderEvent::SENT_FOR_APPROVAL, OrderEvent::CONFIRMED, OrderEvent::APPROVED], $order->events()->pluck('action')->all());
        $this->assertStringContainsString('approved', implode(' ', $this->telegramTexts('111')));
    }

    public function test_an_admin_sends_it_back_with_a_required_reason(): void
    {
        Queue::fake();
        $order = $this->pending();

        $page = Livewire::actingAs($this->admin)->test(Show::class, ['order' => $order]);
        $page->set('rejectReason', '  ')->call('reject')->assertHasErrors('rejectReason');
        $this->assertSame(OrderStatus::PendingApproval, $order->fresh()->status);

        $page->set('rejectReason', 'Too many colas')->call('reject')->assertHasNoErrors();

        $order = $order->fresh();
        $this->assertSame(OrderStatus::Draft, $order->status);
        $this->assertSame('rejected', $order->review_status);
        $this->assertSame('Too many colas', $order->review_note);
        Queue::assertNothingPushed();
        $event = $order->events()->where('action', OrderEvent::REJECTED)->first();
        $this->assertSame('Too many colas', $event->note);
        $this->assertSame($this->admin->id, $event->user_id);
        $this->assertStringContainsString('Too many colas', implode(' ', $this->telegramTexts('111')));

        // The operator sees the reason and can edit and confirm again.
        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $order])->assertSee('Too many colas')->call('saveQty', $this->cola->id, '10')->assertHasNoErrors();
        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $order])->call('confirm');
        $order = $order->fresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertNull($order->review_status);
        $this->assertNull($order->review_note);
    }

    public function test_an_operator_cannot_approve_or_reject_and_another_shops_operator_cannot_see_it(): void
    {
        $order = $this->pending();
        $other = User::factory()->operator()->create();
        $other->shops()->attach(Shop::factory()->create()->id);

        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $order])->call('approve')->assertForbidden();
        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $order])->set('rejectReason', 'x')->call('reject')->assertForbidden();
        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $order])->call('confirm')->assertForbidden();
        Livewire::actingAs($this->aziz)->test(Index::class)->call('confirm', $order->id)->assertForbidden();
        Livewire::actingAs($other)->test(Show::class, ['order' => $order])->assertNotFound();
        $this->actingAs($other)->get('/orders')->assertOk()->assertDontSee($order->number());
        $this->assertSame(OrderStatus::PendingApproval, $order->fresh()->status);
    }

    public function test_an_admin_sees_the_exceptions_first_and_confirms_anyway(): void
    {
        Queue::fake();
        $this->line($this->draft, $this->cola, 31, 10);

        $page = Livewire::actingAs($this->admin)->test(Show::class, ['order' => $this->draft])->call('confirm');

        $page->assertSee('This order has exceptions')->assertSee('more than 3 x the maximum');
        $this->assertSame(OrderStatus::Draft, $this->draft->fresh()->status);
        Queue::assertNothingPushed();

        $page->call('confirm', true);

        $order = $this->draft->fresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame('approved', $order->review_status);
        Queue::assertPushed(SyncOrderTo1C::class);
        $event = $order->events()->where('action', OrderEvent::EXCEPTION_APPROVED)->first();
        $this->assertStringContainsString('more than 3 x the maximum', $event->note);
    }

    public function test_an_operator_cannot_skip_the_review_by_passing_anyway(): void
    {
        Queue::fake();
        $this->line($this->draft, $this->cola, 31, 10);

        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $this->draft])->call('confirm', true);

        $this->assertSame(OrderStatus::PendingApproval, $this->draft->fresh()->status);
        Queue::assertNothingPushed();
    }

    public function test_confirming_through_the_phone_link_with_exceptions_sends_it_for_approval(): void
    {
        Queue::fake();
        $this->line($this->draft, $this->cola, 31, 10);
        $token = OrderLink::forUser($this->draft, $this->aziz)->token;

        $page = Livewire::test(PublicDraft::class, ['token' => $token])->call('confirm');

        $page->assertSee('Sent for approval')->assertSee('more than 3 x the maximum');
        $order = $this->draft->fresh();
        $this->assertSame(OrderStatus::PendingApproval, $order->status);
        $this->assertSame($this->aziz->id, $order->events()->where('action', OrderEvent::SENT_FOR_APPROVAL)->first()->user_id);
        $this->assertSame('link', $order->events()->where('action', OrderEvent::SENT_FOR_APPROVAL)->first()->channel);
        Queue::assertNothingPushed();

        // Reopening the link shows the waiting state, read-only; editing is refused.
        $again = Livewire::test(PublicDraft::class, ['token' => $token])->assertSee('Sent for approval');
        $again->call('saveQty', $this->cola->id, '5')->assertNotFound();
        $this->assertEquals(31, $order->items()->first()->qty);
    }

    public function test_the_unconfirmed_report_lists_orders_waiting_for_approval_amber_after_two_hours(): void
    {
        $order = $this->pending();
        $service = app(UnconfirmedOrders::class);

        $row = $service->rows(null, 'approval')->first();
        $this->assertSame($order->id, $row['order']->id);
        $this->assertSame(['approval'], $row['kinds']);
        $this->assertSame('none', $row['severity']);

        $this->assertSame('amber', $service->rows(null, 'approval', now()->addMinutes(121))->first()['severity']);
        $this->assertSame('red', $service->rows(null, 'approval', now()->addHours(25))->first()['severity']);
        $this->assertCount(0, $service->rows(null, 'draft'));
    }

    public function test_the_orders_list_shows_the_waiting_badge_and_filters_them(): void
    {
        $order = $this->pending();
        $plain = $this->order();
        $this->line($plain, $this->water, 1, 1);

        Livewire::actingAs($this->admin)->test(Index::class)->assertSee('Waiting for approval')->assertSee($plain->number())
            ->set('filter', 'approval')->assertSee($order->number())->assertDontSee($plain->number());
    }

    public function test_an_index_confirm_of_a_draft_does_not_skip_the_exception_check(): void
    {
        Queue::fake();
        $this->line($this->draft, $this->cola, 31, 10);

        Livewire::actingAs($this->admin)->test(Index::class)->call('confirm', $this->draft->id)->assertRedirect(route('orders.show', $this->draft));

        $this->assertSame(OrderStatus::Draft, $this->draft->fresh()->status);
        Queue::assertNothingPushed();
    }
}
