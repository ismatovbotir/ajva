<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Jobs\SendOrderTelegram;
use App\Livewire\Orders\Index;
use App\Livewire\Orders\Show;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderLink;
use App\Models\Shop;
use App\Models\User;
use App\Services\OrderTelegram;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** The Telegram outcome stored on a draft (badge + Send / Resend on the Orders list and the order page). */
class OrderTelegramStatusTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Order $draft;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        config(['services.telegram.bot_token' => 'BOT:TOKEN', 'app.url' => 'https://panel.test']);
        $this->shop = Shop::factory()->create(['name' => 'Alpha']);
        $this->admin = User::factory()->create();
        $this->draft = Order::query()->create(['shop_id' => $this->shop->id, 'status' => OrderStatus::Draft]);
        $this->draft->items()->create(['item_id' => Item::factory()->create(['name' => 'Cola'])->id, 'qty' => 5]);
        $this->telegram(fn () => Http::response(['ok' => true], 200));
    }

    private function operator(string $name, ?string $chat, ?Shop $shop = null): User
    {
        $user = User::factory()->operator()->create(['name' => $name, 'telegram_chat_id' => $chat]);
        $user->shops()->attach(($shop ?? $this->shop)->id);

        return $user;
    }

    /** Replaces any earlier fake. */
    private function telegram(callable $reply): void
    {
        Http::swap(new Factory);
        Http::fake(['api.telegram.org/*' => $reply]);
    }

    public function test_a_draft_never_sent_shows_not_sent_and_the_admin_gets_a_send_button(): void
    {
        $this->operator('Aziz', '4242');

        Livewire::actingAs($this->admin)->test(Index::class)
            ->assertSeeHtml('data-telegram-badge="none"')->assertSee('Not sent')->assertSeeHtml('>Send</button>');
    }

    public function test_sending_stores_the_outcome_and_the_button_becomes_resend(): void
    {
        $this->operator('Aziz', '4242');
        $this->operator('Bekzod', '5555');

        Livewire::actingAs($this->admin)->test(Index::class)
            ->call('sendTelegram', $this->draft->id)
            ->assertSeeHtml('data-telegram-badge="sent"')->assertSee('Sent')->assertSeeHtml('>Resend</button>');

        $order = $this->draft->fresh();
        $this->assertSame('sent', $order->telegram_status);
        $this->assertSame(2, $order->telegram_recipients_ok);
        $this->assertNotNull($order->telegram_sent_at);
        Http::assertSentCount(2);

        // Resend goes to everybody again.
        Livewire::actingAs($this->admin)->test(Index::class)->call('sendTelegram', $this->draft->id);
        Http::assertSentCount(4);
    }

    public function test_each_attempt_is_logged_per_person_and_shown_on_the_order_page(): void
    {
        $aziz = $this->operator('Aziz', '4242');
        $this->operator('Bekzod', '5555');
        $this->telegram(fn ($request) => $request['chat_id'] === '5555'
            ? Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)
            : Http::response(['ok' => true, 'result' => ['message_id' => 77]], 200));

        Livewire::actingAs($this->admin)->test(Index::class)->call('sendTelegram', $this->draft->id);

        $ok = \App\Models\TelegramMessage::query()->where('order_id', $this->draft->id)->where('user_id', $aziz->id)->first();
        $this->assertSame('sent', $ok->status);
        $this->assertSame(77, $ok->message_id);
        $this->assertSame(4242, $ok->chat_id);
        $this->assertSame('out', $ok->direction);
        $bad = \App\Models\TelegramMessage::query()->where('order_id', $this->draft->id)->where('status', 'failed')->first();
        $this->assertStringContainsString('blocked', $bad->error);

        \App\Models\OrderLink::forUser($this->draft, $aziz)->forceFill(['last_used_at' => now()])->save();

        Livewire::actingAs($this->admin)->test(\App\Livewire\Orders\Show::class, ['order' => $this->draft])
            ->assertSeeHtml('data-telegram-deliveries')->assertSee('Aziz')->assertSee('Link opened')->assertSee('Bekzod')->assertSee('blocked');
    }

    public function test_the_send_command_sends_unsent_drafts_and_skips_sent_ones_unless_asked(): void
    {
        $this->operator('Aziz', '4242');

        $this->artisan('orders:send-telegram')->assertExitCode(0);
        $this->assertSame('sent', $this->draft->fresh()->telegram_status);
        Http::assertSentCount(1);

        // Already sent: nothing happens without an argument, --all or the order number resends.
        $this->artisan('orders:send-telegram')->expectsOutput('No draft orders to send.')->assertExitCode(0);
        Http::assertSentCount(1);
        $this->artisan('orders:send-telegram', ['order' => $this->draft->number()])->assertExitCode(0);
        Http::assertSentCount(2);
        $this->artisan('orders:send-telegram', ['--all' => true])->assertExitCode(0);
        Http::assertSentCount(3);
    }

    public function test_the_send_command_fails_when_telegram_rejects_and_retries_only_missing_people(): void
    {
        $this->operator('Aziz', '4242');
        $this->operator('Bekzod', '5555');
        $this->telegram(fn ($request) => $request['chat_id'] === '5555'
            ? Http::response(['ok' => false, 'description' => 'chat not found'], 400)
            : Http::response(['ok' => true], 200));

        $this->artisan('orders:send-telegram')->assertExitCode(1);
        Http::assertSentCount(2);

        // The retry goes only to Bekzod, Aziz already has the message.
        $this->artisan('orders:send-telegram')->assertExitCode(1);
        Http::assertSentCount(3);
    }

    public function test_a_failure_shows_failed_with_the_reason(): void
    {
        $this->operator('Aziz', '4242');
        $this->telegram(fn () => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403));

        Livewire::actingAs($this->admin)->test(Index::class)
            ->call('sendTelegram', $this->draft->id)
            ->assertSeeHtml('data-telegram-badge="failed"')->assertSee('Failed')->assertSee('blocked');

        $order = $this->draft->fresh();
        $this->assertSame('failed', $order->telegram_status);
        $this->assertSame(0, $order->telegram_recipients_ok);
        $this->assertNull($order->telegram_sent_at);
        $this->assertStringNotContainsString('BOT:TOKEN', (string) $order->telegram_note);
    }

    public function test_nobody_to_send_to_shows_no_recipients_and_inactive_users_are_not_recipients(): void
    {
        $gone = $this->operator('Gone', '4242');
        $gone->update(['is_active' => false]);
        $this->operator('No Telegram', null);

        Livewire::actingAs($this->admin)->test(Index::class)
            ->call('sendTelegram', $this->draft->id)
            ->assertSeeHtml('data-telegram-badge="no_recipients"')->assertSee('No recipients');

        Http::assertNothingSent();
        $this->assertSame('no_recipients', $this->draft->fresh()->telegram_status);
    }

    public function test_an_operator_sees_the_badge_but_has_no_button_and_cannot_send(): void
    {
        $aziz = $this->operator('Aziz', '4242');
        $this->draft->forceFill(['telegram_status' => 'sent', 'telegram_recipients_ok' => 1, 'telegram_sent_at' => now()])->save();

        Livewire::actingAs($aziz)->test(Index::class)
            ->assertSeeHtml('data-telegram-badge="sent"')->assertDontSeeHtml('>Resend</button>')->assertDontSeeHtml('>Send</button>')
            ->call('sendTelegram', $this->draft->id)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_only_drafts_have_a_telegram_cell_and_a_confirmed_order_cannot_be_sent(): void
    {
        $this->draft->update(['status' => OrderStatus::Confirmed]);

        Livewire::actingAs($this->admin)->test(Index::class)
            ->assertDontSeeHtml('data-telegram-badge')
            ->call('sendTelegram', $this->draft->id)->assertNotFound();
    }

    public function test_the_order_page_shows_the_same_badge(): void
    {
        $this->operator('Aziz', '4242');

        Livewire::actingAs($this->admin)->test(Show::class, ['order' => $this->draft])
            ->assertSeeHtml('data-telegram-badge="none"')
            ->call('sendTelegram')
            ->assertSeeHtml('data-telegram-badge="sent"');
    }

    public function test_a_retry_only_targets_users_that_still_failed(): void
    {
        $aziz = $this->operator('Aziz', '4242');
        $bekzod = $this->operator('Bekzod', '5555');
        $this->telegram(fn ($r) => $r['chat_id'] === '5555'
            ? Http::response(['ok' => false, 'description' => 'Too Many Requests'], 429)
            : Http::response(['ok' => true], 200));

        $first = app(OrderTelegram::class)->send($this->draft);
        $this->assertSame('failed', $first['status']);
        $this->assertSame([$bekzod->id], $first['failed_user_ids']);
        $this->assertSame(1, $this->draft->fresh()->telegram_recipients_ok);
        Http::assertSentCount(2);

        // The retry job: Aziz already has it. Bekzod still fails once more, then works. Aziz is never messaged again.
        $job = new SendOrderTelegram($this->draft->id, [$bekzod->id]);
        try {
            $job->handle(app(OrderTelegram::class));
            $this->fail('the retry should have thrown while Bekzod fails');
        } catch (\RuntimeException) {
        }
        $this->telegram(fn () => Http::response(['ok' => true], 200));
        $job->handle(app(OrderTelegram::class));

        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['chat_id'] === '5555');
        $order = $this->draft->fresh();
        $this->assertSame('sent', $order->telegram_status);
        $this->assertSame(2, $order->telegram_recipients_ok);
        $this->assertNotNull(OrderLink::forUser($order, $aziz)->telegram_sent_at);
    }

    public function test_after_the_last_retry_the_order_shows_failed(): void
    {
        $this->operator('Aziz', '4242');
        $this->telegram(fn () => Http::response(['ok' => false, 'description' => 'Bad Gateway'], 502));
        app(OrderTelegram::class)->send($this->draft);

        (new SendOrderTelegram($this->draft->id))->failed(new \RuntimeException('x'));

        $order = $this->draft->fresh();
        $this->assertSame('failed', $order->telegram_status);
        $this->assertStringContainsString('still failing', (string) $order->telegram_note);
    }
}
