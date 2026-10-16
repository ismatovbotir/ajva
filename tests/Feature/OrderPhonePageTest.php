<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\SyncOrderTo1C;
use App\Livewire\Orders\PublicDraft;
use App\Livewire\Orders\Show;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderLink;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** The phone page of the order link (fixed top bar with Accept, editable quantities, the comment last) and the links shown on the order page. */
class OrderPhonePageTest extends TestCase
{
    use RefreshDatabase;

    private Order $draft;

    private User $aziz;

    private OrderLink $link;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        $shop = Shop::factory()->create(['name' => 'Alpha']);
        $this->draft = Order::query()->create(['shop_id' => $shop->id, 'status' => OrderStatus::Draft, 'horizon_days' => 3]);
        foreach (['Cola', 'Bread'] as $name) {
            $this->draft->items()->create(['item_id' => Item::factory()->create(['name' => $name])->id, 'qty' => 10, 'suggested_qty' => 10, 'est' => 2, 'min' => 5]);
        }
        $this->aziz = User::factory()->operator()->create(['name' => 'Aziz']);
        $this->aziz->shops()->attach($shop->id);
        $this->link = OrderLink::forUser($this->draft, $this->aziz);
    }

    public function test_the_phone_page_has_a_fixed_top_bar_with_accept_the_lines_and_the_comment_last(): void
    {
        $html = $this->get('/o/'.$this->link->token)->assertOk()->getContent();

        $this->assertStringContainsString('fixed inset-x-0 top-0', $html);
        $this->assertStringContainsString('data-accept', $html);
        $this->assertStringContainsString('width=device-width', $html);
        $this->assertStringContainsString('inputmode="decimal"', $html, 'a numeric keypad for the quantities');
        $this->assertStringNotContainsString('nav-list', $html, 'no side menu');

        $positions = [strpos($html, 'data-accept'), strpos($html, 'data-lines'), strpos($html, 'Add an item'), strpos($html, 'id="order-comment"')];
        $this->assertSame($positions, collect($positions)->sort()->values()->all(), 'top bar, items, add box, then the comment at the end');
        $this->assertSame(strrpos($html, '<textarea'), strpos($html, '<textarea'), 'the comment is the only text field after the items');
    }

    public function test_the_comment_is_saved_recorded_and_sent_to_1c(): void
    {
        Queue::fake();
        $page = Livewire::test(PublicDraft::class, ['token' => $this->link->token])
            ->set('comment', '  Deliver after 14:00 <b>please</b>  ')->call('saveComment');

        $this->assertSame('Deliver after 14:00 please', $this->draft->fresh()->comment, 'trimmed, tags stripped');
        $event = $this->draft->events()->where('action', 'comment')->firstOrFail();
        $this->assertSame([$this->aziz->id, 'link', 'Deliver after 14:00 please'], [$event->user_id, $event->channel, $event->note]);

        // The same text again records nothing new.
        $page->call('saveComment');
        $this->assertSame(1, $this->draft->events()->where('action', 'comment')->count());

        $page->set('comment', str_repeat('x', 1001))->call('saveComment')->assertHasErrors('comment');
        $this->assertSame('Deliver after 14:00 please', $this->draft->fresh()->comment);
    }

    public function test_pressing_accept_saves_a_comment_that_was_never_blurred_and_1c_gets_it(): void
    {
        config(['services.onec.orders_url' => 'https://onec.test/orders', 'services.onec.token' => 'secret']);
        Http::fake(['onec.test/*' => Http::response(['ok' => true], 200)]);

        Livewire::test(PublicDraft::class, ['token' => $this->link->token])
            ->set('comment', 'Call before delivery')->call('confirm');

        $order = $this->draft->fresh();
        $this->assertSame(OrderStatus::Confirmed, $order->status);
        $this->assertSame('Call before delivery', $order->comment);

        Http::assertSent(fn ($request) => $request->url() === 'https://onec.test/orders' && $request['comment'] === 'Call before delivery' && count($request['lines']) === 2);
    }

    public function test_a_confirmed_order_ignores_a_late_comment(): void
    {
        $page = Livewire::test(PublicDraft::class, ['token' => $this->link->token])->set('comment', 'too late');
        $this->draft->forceFill(['status' => OrderStatus::Confirmed])->save();

        $page->call('saveComment')->assertNotFound();
        $this->assertNull($this->draft->fresh()->comment);
    }

    public function test_the_order_page_shows_the_links_to_the_admin_and_the_own_link_to_the_operator(): void
    {
        $other = User::factory()->operator()->create(['name' => 'Bekzod']);
        $other->shops()->attach($this->draft->shop_id);

        Livewire::actingAs(User::factory()->create())->test(Show::class, ['order' => $this->draft])
            ->assertSeeHtml('data-order-links')
            ->assertSee('Aziz')->assertSee('Bekzod')
            ->assertSee($this->link->url())
            ->assertSee(OrderLink::forUser($this->draft, $other)->url());

        $own = Livewire::actingAs($other)->test(Show::class, ['order' => $this->draft])
            ->assertSeeHtml('data-my-link')
            ->assertSee(OrderLink::forUser($this->draft, $other)->url())
            ->assertDontSee($this->link->url(), 'never another person\'s link');
        $own->assertDontSeeHtml('data-order-links');
    }

    public function test_an_operator_comments_in_the_panel_and_a_confirmed_order_shows_it_read_only(): void
    {
        Queue::fake();
        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $this->draft])
            ->set('comment', 'Fragile')->call('saveComment');
        $this->assertSame('Fragile', $this->draft->fresh()->comment);
        $this->assertSame('panel', $this->draft->events()->where('action', 'comment')->value('channel'));

        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $this->draft->fresh()])->call('confirm');
        Queue::assertPushed(SyncOrderTo1C::class);

        Livewire::actingAs($this->aziz)->test(Show::class, ['order' => $this->draft->fresh()])
            ->assertSeeHtml('data-comment')->assertSee('Fragile')->assertDontSeeHtml('id="order-comment"');
    }
}
