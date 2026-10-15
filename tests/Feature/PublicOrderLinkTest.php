<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use App\Jobs\SyncOrderTo1C;
use App\Livewire\Orders\PublicDraft;
use App\Livewire\Orders\Show;
use App\Models\Item;
use App\Models\ItemPrice;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderLink;
use App\Models\Price;
use App\Models\Shop;
use App\Models\User;
use App\Services\OrderTelegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

/** The personal link of a draft (/o/{token}): one user per link, works only while the order is a draft and the user may act, every change is recorded for him. */
class PublicOrderLinkTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Item $cola;

    private Order $draft;

    private User $aziz;

    private OrderLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');   // the assertions read the English texts
        $this->shop = Shop::factory()->create(['name' => 'Alpha']);
        $this->cola = Item::factory()->create(['name' => 'Cola']);
        $this->draft = Order::query()->create(['shop_id' => $this->shop->id, 'status' => OrderStatus::Draft, 'horizon_days' => 3]);
        $this->draft->items()->create(['item_id' => $this->cola->id, 'qty' => 10, 'suggested_qty' => 10, 'est' => 2, 'min' => 5, 'flags' => 'out']);
        $this->aziz = $this->operator('Aziz', 'aziz@example.test', '4242');
        $this->link = OrderLink::forUser($this->draft, $this->aziz);
    }

    private function operator(string $name, string $email, ?string $chat = null): User
    {
        $user = User::factory()->operator()->create(['name' => $name, 'email' => $email, 'telegram_chat_id' => $chat]);
        $user->shops()->attach($this->shop->id);

        return $user;
    }

    private function token(): string
    {
        return $this->link->token;
    }

    public function test_a_link_is_per_user_and_order_and_created_once(): void
    {
        $bekzod = $this->operator('Bekzod', 'bekzod@example.test');

        $other = OrderLink::forUser($this->draft, $bekzod);

        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->token());
        $this->assertNotSame($this->token(), $other->token);
        $this->assertSame($this->link->id, OrderLink::forUser($this->draft, $this->aziz)->id);
        $this->assertSame(2, OrderLink::query()->count());
        $this->assertNull($this->link->fresh()->last_used_at);
    }

    public function test_the_draft_page_opens_without_login_marks_the_use_and_is_not_indexable(): void
    {
        $response = $this->get('/o/'.$this->token());

        $response->assertOk()->assertSee('Cola')->assertSee($this->draft->number())->assertSee('Alpha')->assertSee('Aziz');
        $this->assertStringContainsString('noindex', (string) $response->headers->get('X-Robots-Tag'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $response->assertSee('noindex', false);
        $this->assertNotNull($this->link->fresh()->last_used_at);
    }

    public function test_unknown_malformed_confirmed_and_deleted_tokens_are_a_plain_404(): void
    {
        $this->get('/o/'.'00000000-0000-4000-8000-000000000000')->assertNotFound();
        $this->get('/o/not-a-token')->assertNotFound();
        $this->get('/o/'.strtoupper($this->token()))->assertNotFound();

        $token = $this->token();
        $this->draft->update(['status' => OrderStatus::Confirmed]);
        $this->get('/o/'.$token)->assertNotFound();

        $this->draft->update(['status' => OrderStatus::Draft]);
        $this->get('/o/'.$token)->assertOk();
        $this->draft->items()->delete();
        $this->draft->delete();
        $this->get('/o/'.$token)->assertNotFound();
    }

    public function test_the_link_dies_with_the_user_being_inactive_unassigned_or_not_an_operator(): void
    {
        $this->aziz->update(['is_active' => false]);
        $this->get('/o/'.$this->token())->assertNotFound();
        Livewire::test(PublicDraft::class, ['token' => $this->token()])->assertNotFound();

        $this->aziz->update(['is_active' => true]);
        $this->get('/o/'.$this->token())->assertOk();

        $this->aziz->shops()->detach();
        $this->get('/o/'.$this->token())->assertNotFound();

        $this->aziz->shops()->attach($this->shop->id);
        $this->aziz->update(['role' => 'admin']);
        $this->get('/o/'.$this->token())->assertNotFound();
    }

    public function test_an_open_page_stops_working_when_the_user_is_deactivated_meanwhile(): void
    {
        $component = Livewire::test(PublicDraft::class, ['token' => $this->token()]);

        $this->aziz->update(['is_active' => false]);

        $component->call('saveQty', $this->cola->id, '99')->assertNotFound();
        $this->assertEquals(10, $this->draft->items()->first()->qty);
        $this->assertSame(0, OrderEvent::query()->count());
    }

    public function test_the_manager_edits_adds_and_removes_lines_and_every_change_is_recorded_for_him(): void
    {
        $water = Item::factory()->create(['name' => 'Water bottle']);

        $component = Livewire::test(PublicDraft::class, ['token' => $this->token()])
            ->call('saveQty', $this->cola->id, '4')
            ->call('saveQty', $this->cola->id, '4')   // unchanged: no second event
            ->set('search', 'Water')->assertSee('Water bottle')
            ->set('addQty', '6')->call('addItem', $water->id);

        $items = $this->draft->items()->get()->keyBy('item_id');
        $this->assertEquals(4, $items[$this->cola->id]->qty);
        $this->assertEquals(6, $items[$water->id]->qty);
        $this->assertTrue($items[$water->id]->added_manually);

        foreach (['0', '-1', 'abc'] as $bad) {
            $component->call('saveQty', $this->cola->id, $bad)->assertHasErrors('qty');
        }
        $component->call('removeItem', $this->cola->id);
        $this->assertSame([$water->id], $this->draft->items()->pluck('item_id')->all());

        $events = $this->draft->events()->orderBy('id')->get();
        $this->assertSame(['qty_changed', 'line_added', 'line_removed'], $events->pluck('action')->all());
        $this->assertSame([$this->aziz->id], $events->pluck('user_id')->unique()->all());
        $this->assertSame(['link'], $events->pluck('channel')->unique()->all());
        $this->assertEquals([10, 4], [$events[0]->old_qty, $events[0]->new_qty]);
        $this->assertEquals($water->id, $events[1]->item_id);
        $this->assertEquals(4, $events[2]->old_qty);

        $component->assertSee('Changes')->assertSee('Cola: 10 → 4')->assertSee('Added Water bottle');
    }

    public function test_confirming_records_the_link_user_queues_the_sync_and_kills_the_link(): void
    {
        Queue::fake();

        $component = Livewire::test(PublicDraft::class, ['token' => $this->token()])->call('confirm');

        $order = $this->draft->fresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame($this->aziz->id, $order->confirmed_by);
        $this->assertNotNull($order->confirmed_at);
        $this->assertSame(OrderSyncStatus::Queued, $order->sync_status);
        Queue::assertPushed(SyncOrderTo1C::class, fn ($job) => $job->orderId === $order->id);
        $component->assertSee('no longer works');
        $event = $order->events()->where('action', 'confirmed')->firstOrFail();
        $this->assertSame([$this->aziz->id, 'link'], [$event->user_id, $event->channel]);

        // From now on the link and every action on an open page are a 404; nothing can be edited.
        $this->get('/o/'.$this->token())->assertNotFound();
        $component->call('saveQty', $this->cola->id, '99')->assertNotFound();
        $this->assertEquals(10, $order->items()->first()->qty);
        Livewire::test(PublicDraft::class, ['token' => $this->token()])->assertNotFound();
    }

    public function test_actions_on_an_open_page_fail_once_the_order_is_confirmed_elsewhere(): void
    {
        $component = Livewire::test(PublicDraft::class, ['token' => $this->token()]);

        $this->draft->update(['status' => OrderStatus::Confirmed]);

        $component->call('saveQty', $this->cola->id, '99')->assertNotFound();
        $this->assertEquals(10, $this->draft->items()->first()->qty);
        Livewire::test(PublicDraft::class, ['token' => $this->token()])->assertNotFound();
    }

    public function test_an_empty_order_cannot_be_confirmed(): void
    {
        $this->draft->items()->delete();

        Livewire::test(PublicDraft::class, ['token' => $this->token()])->call('confirm')->assertHasErrors('confirm');

        $this->assertSame(OrderStatus::Draft, $this->draft->fresh()->status);
    }

    public function test_the_token_cannot_be_changed_from_the_browser(): void
    {
        $bekzod = $this->operator('Bekzod', 'bekzod@example.test');
        $other = OrderLink::forUser($this->draft, $bekzod);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        Livewire::test(PublicDraft::class, ['token' => $this->token()])->set('token', $other->token);
    }

    public function test_two_users_have_two_links_and_their_changes_are_told_apart(): void
    {
        $bekzod = $this->operator('Bekzod', 'bekzod@example.test');
        $second = OrderLink::forUser($this->draft, $bekzod);

        Livewire::test(PublicDraft::class, ['token' => $this->token()])->call('saveQty', $this->cola->id, '7');
        Livewire::test(PublicDraft::class, ['token' => $second->token])->call('saveQty', $this->cola->id, '8')
            ->assertSee('Cola: 10 → 7')->assertSee('Cola: 7 → 8')
            ->assertSee('Aziz')->assertSee('You')
            ->assertDontSee('aziz@example.test')->assertDontSee('bekzod@example.test');

        $this->assertSame([$this->aziz->id, $bekzod->id], $this->draft->events()->orderBy('id')->pluck('user_id')->all());
    }

    public function test_no_cost_profit_or_cashier_data_is_on_the_page(): void
    {
        $cost = Price::factory()->create(['id' => (int) config('inventory.cost_price_id'), 'name' => 'Cost price', 'is_sell' => false]);
        ItemPrice::factory()->create(['item_id' => $this->cola->id, 'price_id' => $cost->id, 'value' => 98765.43]);
        $receipt = \App\Models\Receipt::factory()->create(['shop_id' => $this->shop->id, 'cashier' => 'Secret Cashier']);
        \App\Models\ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $this->cola->id]);

        $html = $this->get('/o/'.$this->token())->assertOk()->getContent();

        foreach (['98765', '98 765', 'Secret Cashier', 'Cost price', 'margin', 'profit', 'aziz@example.test'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html);
        }
    }

    public function test_the_admin_copies_the_link_of_a_chosen_user_and_does_not_become_the_actor(): void
    {
        $admin = User::factory()->create();
        $bekzod = $this->operator('Bekzod', 'bekzod@example.test');
        $gone = $this->operator('Gone', 'gone@example.test');
        $gone->update(['is_active' => false]);

        $component = Livewire::actingAs($admin)->test(Show::class, ['order' => $this->draft])
            ->assertSee('Aziz')->assertSee('Bekzod')->assertDontSee('Gone')
            ->call('makeLink')->assertHasErrors('linkUserId')
            ->set('linkUserId', (string) $gone->id)->call('makeLink')->assertHasErrors('linkUserId')
            ->set('linkUserId', (string) $bekzod->id)->call('makeLink')->assertHasNoErrors();

        $url = OrderLink::forUser($this->draft, $bekzod)->url();
        $component->assertSet('generatedLink', $url)->assertDispatched('order-link-ready', url: $url);

        // Editing through that link is recorded for Bekzod, never for the admin.
        $token = OrderLink::forUser($this->draft, $bekzod)->token;
        Livewire::test(PublicDraft::class, ['token' => $token])->call('saveQty', $this->cola->id, '3');
        $this->assertSame($bekzod->id, $this->draft->events()->firstOrFail()->user_id);
    }

    public function test_an_operator_cannot_make_or_send_links(): void
    {
        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $this->draft])
            ->assertDontSee('Copy link')
            ->call('makeLink')->assertForbidden();
        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $this->draft])->call('sendTelegram')->assertForbidden();
    }

    public function test_every_recipient_gets_his_own_link_and_a_confirmed_order_has_nothing_to_share(): void
    {
        config(['services.telegram.bot_token' => 'BOT:TOKEN', 'app.url' => 'https://panel.test']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true], 200)]);
        $admin = User::factory()->create();
        $bekzod = $this->operator('Bekzod', 'bekzod@example.test', '5555');

        Livewire::actingAs($admin)->test(Show::class, ['order' => $this->draft])
            ->call('sendTelegram')
            ->assertSet('telegramStatus', 'sent');

        $urls = [];
        Http::assertSent(function ($r) use (&$urls) {
            $urls[$r['chat_id']] = $r['reply_markup']['inline_keyboard'][0][0]['url'];

            return true;
        });
        $this->assertSame('https://panel.test/o/'.$this->token(), $urls['4242']);
        $this->assertSame('https://panel.test/o/'.OrderLink::forUser($this->draft, $bekzod)->token, $urls['5555']);
        $this->assertNotSame($urls['4242'], $urls['5555']);

        $this->draft->update(['status' => OrderStatus::Confirmed]);
        Livewire::actingAs($admin)->test(Show::class, ['order' => $this->draft])->assertDontSee('Copy link');
    }

    public function test_panel_edits_are_recorded_with_the_signed_in_user_and_shown_as_changes(): void
    {
        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $this->draft])
            ->call('saveQty', $this->cola->id, '5')
            ->assertSee('Cola: 10 → 5')->assertSee('in the panel');

        $event = $this->draft->events()->firstOrFail();
        $this->assertSame([$this->aziz->id, 'panel', 'qty_changed'], [$event->user_id, $event->channel, $event->action]);

        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $this->draft])->call('confirm');
        $this->assertSame($this->aziz->id, $this->draft->fresh()->confirmed_by);
        $this->assertSame('panel', $this->draft->events()->where('action', 'confirmed')->firstOrFail()->channel);
    }

    public function test_the_telegram_message_has_the_summary_markers_and_no_prices(): void
    {
        config(['app.url' => 'https://panel.test']);
        foreach (range(1, 9) as $i) {
            $item = Item::factory()->create(['name' => 'Item '.$i]);
            $this->draft->items()->create(['item_id' => $item->id, 'qty' => $i, 'suggested_qty' => $i + 2, 'est' => 3, 'cover_days' => $i, 'flags' => $i === 1 ? 'short,understated' : null]);
        }
        $this->draft->update(['warnings' => 'stale=2,capped=1']);

        $text = app(OrderTelegram::class)->message($this->draft->fresh());

        $this->assertStringContainsString('Alpha', $text);
        $this->assertStringContainsString('10 lines', $text);
        $this->assertStringContainsString('Cola', $text);                       // out of stock goes first
        $this->assertStringContainsString('out of stock', $text);
        $this->assertStringContainsString('warehouse is short', $text);
        $this->assertStringContainsString('demand understated', $text);
        $this->assertStringContainsString('+ 2 more lines', $text);             // only the top 8 are listed
        $this->assertStringContainsString('2 items left out', $text);
        $this->assertStringContainsString('stops working once the order is confirmed', $text);
        $this->assertStringNotContainsString('/o/', $text);                     // the link is the button, not part of the text
    }
}
