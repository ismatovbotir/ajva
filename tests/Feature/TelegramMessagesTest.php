<?php

namespace Tests\Feature;

use App\Livewire\Telegram\Messages;
use App\Livewire\Telegram\Users as TelegramUsersPage;
use App\Models\TelegramMessage;
use App\Models\Telegram as TelegramAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** telegram:poll (getUpdates, no webhook) stores people and messages; the admin can answer from the panel. */
class TelegramMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        config(['services.telegram.bot_token' => 'BOT:TOKEN']);
    }

    private function update(int $id, int $from, string $text): array
    {
        return [
            'update_id' => $id,
            'message' => [
                'message_id' => $id + 100,
                'from' => ['id' => $from, 'is_bot' => false, 'first_name' => 'Aziz', 'username' => 'aziz'],
                'chat' => ['id' => $from, 'type' => 'private'],
                'date' => 1760000000,
                'text' => $text,
            ],
        ];
    }

    public function test_poll_stores_users_messages_and_offset(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => [
            $this->update(10, 555, 'hello'),
            $this->update(11, 555, 'again'),
            ['update_id' => 12, 'message' => ['message_id' => 1, 'from' => ['id' => 777, 'first_name' => 'Bek'], 'chat' => ['id' => 777, 'type' => 'private'], 'date' => 1760000100, 'photo' => [['file_id' => 'x']]]],
        ]])]);

        Artisan::call('telegram:poll');

        $this->assertSame(2, TelegramAccount::query()->count());
        $this->assertSame(3, TelegramMessage::query()->count());
        $first = TelegramMessage::query()->where('update_id', 10)->first();
        $this->assertSame(555, $first->chat_id);
        $this->assertSame(555, $first->from_id);
        $this->assertSame('text', $first->type);
        $this->assertSame('in', $first->direction);
        $this->assertSame('photo', TelegramMessage::query()->where('update_id', 12)->value('type'));
        $this->assertSame('13', \DB::table('settings')->where('key', 'telegram.offset')->value('value'));

        // The next call asks from the saved offset, and a replayed update is not stored twice.
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => [$this->update(11, 555, 'again')]])]);
        Artisan::call('telegram:poll');
        $this->assertSame(3, TelegramMessage::query()->count());
        Http::assertSent(fn ($r) => ($r['offset'] ?? null) === 13);
    }

    public function test_poll_without_token_does_nothing(): void
    {
        config(['services.telegram.bot_token' => '']);
        Http::fake();

        $this->assertSame(0, Artisan::call('telegram:poll'));
        Http::assertNothingSent();
    }

    public function test_poll_error_keeps_the_offset(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'boom'], 500)]);

        $this->assertSame(1, Artisan::call('telegram:poll'));
        $this->assertNull(\DB::table('settings')->where('key', 'telegram.offset')->value('value'));
    }

    public function test_admin_sends_a_message_and_it_is_logged(): void
    {
        $tu = TelegramAccount::query()->create(['tg_id' => 555, 'first_name' => 'Aziz']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(TelegramUsersPage::class)
            ->call('compose', $tu->id)
            ->set('text', 'Hi there')
            ->call('send')
            ->assertSet('failed', false)
            ->assertSet('text', '');

        Http::assertSent(fn ($r) => str_contains($r->url(), 'sendMessage') && $r['chat_id'] === '555' && $r['text'] === 'Hi there');
        $out = TelegramMessage::query()->where('direction', 'out')->first();
        $this->assertSame('Hi there', $out->text);
        $this->assertSame(555, $out->chat_id);
        $this->assertSame($admin->id, $out->sent_by);
        $this->assertNull($out->update_id);
    }

    public function test_failed_send_is_reported_and_not_logged(): void
    {
        $tu = TelegramAccount::query()->create(['tg_id' => 555]);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'chat not found'], 400)]);

        Livewire::actingAs(User::factory()->create())->test(TelegramUsersPage::class)
            ->call('compose', $tu->id)
            ->set('text', 'Hi')
            ->call('send')
            ->assertSet('failed', true)
            ->assertSet('result', 'chat not found');

        $this->assertSame(0, TelegramMessage::query()->count());
    }

    public function test_status_page_shows_the_last_command_runs(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin)->get(route('telegram.status'))->assertOk()->assertSee('telegram:poll')->assertSee('Never ran');

        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => [$this->update(10, 555, 'hello')]])]);
        Livewire::actingAs($admin)->test(\App\Livewire\Telegram\Status::class)
            ->call('pollNow')
            ->assertSee('Working')->assertSee('New messages: 1');

        Http::swap(new \Illuminate\Http\Client\Factory);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'boom'], 500)]);
        Livewire::actingAs($admin)->test(\App\Livewire\Telegram\Status::class)
            ->call('pollNow')->assertSee('Error')->assertSee('boom');

        Livewire::actingAs($admin)->test(\App\Livewire\Telegram\Status::class)
            ->call('sendNow')->assertSee('No draft orders to send.');

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->operator()->create())->get(route('telegram.status'))->assertForbidden();
    }

    public function test_pages_are_admin_only_and_render(): void
    {
        $tu = TelegramAccount::query()->create(['tg_id' => 555, 'first_name' => 'Aziz']);
        TelegramMessage::query()->create(['telegram_id' => $tu->id, 'chat_id' => 555, 'from_id' => 555, 'text' => 'hello there', 'sent_at' => now()]);

        $this->actingAs(User::factory()->create())->get(route('telegram.users'))->assertOk()->assertSee('Aziz');
        $this->get(route('telegram.messages'))->assertOk()->assertSee('hello there');

        Livewire::test(Messages::class)->set('search', 'nomatch')->assertDontSee('hello there');

        $this->app['auth']->forgetGuards();
        $this->actingAs(User::factory()->operator()->create())->get(route('telegram.users'))->assertForbidden();
        $this->get(route('telegram.messages'))->assertForbidden();
    }
}
