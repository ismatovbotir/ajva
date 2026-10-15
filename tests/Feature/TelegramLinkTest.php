<?php

namespace Tests\Feature;

use App\Livewire\Users\Index as UsersIndex;
use App\Models\TelegramLinkCode;
use App\Models\User;
use App\Services\TelegramLinks;
use App\Services\TelegramUpdates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Connecting a user's Telegram: one-time codes, the polling receiver (no webhook) and the Users modal. */
class TelegramLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        config(['services.telegram.bot_token' => 'BOT:TOKEN', 'services.telegram.bot_username' => 'ajwa_bot']);
        $this->user = User::factory()->operator()->create(['name' => 'Aziz']);
    }

    private function code(?User $user = null, string $expires = '+15 minutes', ?string $usedAt = null): string
    {
        $row = TelegramLinkCode::query()->create([
            'user_id' => ($user ?? $this->user)->id,
            'code' => \Illuminate\Support\Str::random(12),
            'expires_at' => now()->modify($expires),
            'used_at' => $usedAt,
            'created_at' => now(),
        ]);

        return $row->code;
    }

    /** @param  array<int, array<string, mixed>>  $updates */
    private function fakeUpdates(array $updates, int $status = 200): void
    {
        Http::swap(new Factory);
        Http::fake([
            '*/getUpdates' => Http::response($status === 200 ? ['ok' => true, 'result' => $updates] : ['ok' => false, 'description' => 'Conflict: webhook is active'], $status),
            '*/deleteWebhook' => Http::response(['ok' => true, 'result' => true]),
            '*/sendMessage' => Http::response(['ok' => true]),
        ]);
    }

    private function start(int $updateId, string $text, int|string $chat = 777, string $type = 'private'): array
    {
        return ['update_id' => $updateId, 'message' => ['message_id' => 1, 'chat' => ['id' => $chat, 'type' => $type], 'text' => $text]];
    }

    private function poll(): array
    {
        return app(TelegramUpdates::class)->poll(0);
    }

    private function replies(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))
            ->map(fn (Request $r) => [$r['chat_id'], $r['text']])->values()->all();
    }

    public function test_start_with_a_valid_code_stores_the_chat_id_and_the_bot_confirms(): void
    {
        $code = $this->code();
        $this->fakeUpdates([$this->start(10, '/start '.$code)]);

        $result = $this->poll();

        $this->assertSame(1, $result['handled']);
        $this->assertSame('777', $this->user->fresh()->telegram_chat_id);
        $this->assertNotNull(TelegramLinkCode::query()->where('code', $code)->first()->used_at);
        $this->assertSame([['777', 'Telegram is connected. Order drafts for your shops will arrive here.']], $this->replies());
        $this->assertSame(10, app(TelegramUpdates::class)->offset());
    }

    public function test_getupdates_asks_for_the_next_offset_private_messages_only_and_the_offset_survives(): void
    {
        $this->fakeUpdates([$this->start(10, 'hello'), $this->start(11, 'again')]);
        $this->poll();
        $this->assertSame(11, app(TelegramUpdates::class)->offset());

        $this->fakeUpdates([]);
        app(TelegramUpdates::class)->poll(25);

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/getUpdates') && $r['offset'] === 12 && $r['allowed_updates'] === ['message'] && $r['timeout'] === 25);
        // The very first call has no offset at all.
        $this->assertDatabaseHas('settings', ['key' => 'telegram.offset', 'value' => '11']);
    }

    public function test_the_first_call_sends_no_offset(): void
    {
        $this->fakeUpdates([]);

        $this->poll();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/getUpdates') && ! isset($r['offset']));
    }

    public function test_a_replayed_update_is_ignored_and_a_code_works_once(): void
    {
        $code = $this->code();
        $this->fakeUpdates([$this->start(10, '/start '.$code)]);
        $this->poll();
        $this->fakeUpdates([$this->start(10, '/start '.$code)]);   // Telegram sends it again

        $this->poll();

        $this->assertSame([], $this->replies());                       // nothing processed twice
        $this->assertSame(10, app(TelegramUpdates::class)->offset());

        // A NEW message with the same (used) code is refused.
        $other = User::factory()->operator()->create();
        $this->fakeUpdates([$this->start(11, '/start '.$code, 888)]);
        $this->poll();
        $this->assertNull($other->fresh()->telegram_chat_id);
        $this->assertStringContainsString('not valid any more', $this->replies()[0][1]);
    }

    public function test_expired_unknown_and_superseded_codes_are_refused_and_never_block_the_queue(): void
    {
        $expired = $this->code(null, '-1 minute');
        $used = $this->code(null, '+10 minutes', now()->toDateTimeString());
        $this->fakeUpdates([
            $this->start(10, '/start '.$expired),
            $this->start(11, '/start '.$used),
            $this->start(12, '/start NOSUCHCODE1'),
        ]);

        $this->poll();

        $this->assertNull($this->user->fresh()->telegram_chat_id);
        $this->assertCount(3, $this->replies());
        $this->assertStringContainsString('expired', $this->replies()[0][1]);
        $this->assertSame(12, app(TelegramUpdates::class)->offset());

        // A newer code replaces the earlier unused one.
        $first = app(TelegramLinks::class)->issue($this->user);
        $second = app(TelegramLinks::class)->issue($this->user);
        $this->assertSame('expired', app(TelegramLinks::class)->redeem($first->code, '777'));
        $this->assertSame('linked', app(TelegramLinks::class)->redeem($second->code, '777'));
    }

    public function test_a_chat_that_belongs_to_another_user_is_refused(): void
    {
        User::factory()->operator()->create(['name' => 'Taken', 'telegram_chat_id' => '777']);
        $code = $this->code();
        $this->fakeUpdates([$this->start(10, '/start '.$code)]);

        $this->poll();

        $this->assertNull($this->user->fresh()->telegram_chat_id);
        $this->assertNull(TelegramLinkCode::query()->where('code', $code)->first()->used_at);   // the admin can fix it and try again
        $this->assertStringContainsString('already connected to another user', $this->replies()[0][1]);
    }

    public function test_an_inactive_user_cannot_be_connected(): void
    {
        $code = $this->code();
        $this->user->update(['is_active' => false]);
        $this->fakeUpdates([$this->start(10, '/start '.$code)]);

        $this->poll();

        $this->assertNull($this->user->fresh()->telegram_chat_id);
    }

    public function test_groups_other_texts_and_a_bare_start_are_ignored_or_answered_with_a_hint(): void
    {
        $code = $this->code();
        $this->fakeUpdates([
            $this->start(10, '/start '.$code, -100500, 'group'),
            $this->start(11, 'hi'),
            ['update_id' => 12, 'edited_message' => ['text' => 'x']],
            $this->start(13, '/start'),
        ]);

        $this->poll();

        $this->assertNull($this->user->fresh()->telegram_chat_id);
        $this->assertCount(1, $this->replies());                       // only the bare /start gets a hint
        $this->assertSame(13, app(TelegramUpdates::class)->offset());
    }

    public function test_a_bad_update_does_not_stall_the_queue(): void
    {
        $code = $this->code();
        $bad = ['update_id' => 10, 'message' => ['chat' => ['id' => ['not', 'a', 'number'], 'type' => 'private'], 'text' => '/start '.$code]];
        $this->fakeUpdates([$bad, $this->start(11, '/start '.$code)]);

        $result = $this->poll();

        $this->assertSame('777', $this->user->fresh()->telegram_chat_id);
        $this->assertSame(11, app(TelegramUpdates::class)->offset());
        $this->assertNull($result['error']);
    }

    public function test_a_webhook_conflict_deletes_the_webhook_and_asks_again(): void
    {
        $code = $this->code();
        Http::swap(new Factory);
        Http::fake([
            '*/getUpdates' => Http::sequence()
                ->push(['ok' => false, 'description' => 'Conflict: can\'t use getUpdates method while webhook is active'], 409)
                ->push(['ok' => true, 'result' => [$this->start(10, '/start '.$code)]]),
            '*/deleteWebhook' => Http::response(['ok' => true, 'result' => true]),
            '*/sendMessage' => Http::response(['ok' => true]),
        ]);

        $result = $this->poll();

        $this->assertNull($result['error']);
        $this->assertSame('777', $this->user->fresh()->telegram_chat_id);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/deleteWebhook'));
    }

    public function test_a_persistent_conflict_and_network_errors_report_an_error_and_keep_the_offset(): void
    {
        $this->fakeUpdates([$this->start(10, 'x')]);
        $this->poll();

        $this->fakeUpdates([], 409);
        $result = $this->poll();
        $this->assertSame('conflict', $result['error']);
        $this->assertSame(10, app(TelegramUpdates::class)->offset());

        Http::swap(new Factory);
        Http::fake(['*/getUpdates' => fn () => throw new ConnectionException('cURL error 28: timeout BOT:TOKEN')]);
        $result = $this->poll();
        $this->assertNotNull($result['error']);
        $this->assertStringNotContainsString('BOT:TOKEN', $result['error']);
        $this->assertSame(10, app(TelegramUpdates::class)->offset());
    }

    public function test_two_pollers_do_not_run_at_once(): void
    {
        $this->fakeUpdates([$this->start(10, 'x')]);
        $lock = Cache::lock('telegram.poll', 30);
        $this->assertTrue($lock->get());

        $result = $this->poll();

        $this->assertTrue($result['skipped']);
        Http::assertNothingSent();
        $lock->release();
        $this->assertFalse($this->poll()['skipped']);
    }

    public function test_without_a_bot_token_nothing_is_called(): void
    {
        config(['services.telegram.bot_token' => '']);
        Http::fake();

        $this->assertSame('no_token', $this->poll()['error']);
        Http::assertNothingSent();
    }

    public function test_the_command_runs_once_and_is_scheduled_every_minute(): void
    {
        $this->fakeUpdates([$this->start(10, 'x')]);

        $this->artisan('telegram:poll --once')->expectsOutputToContain('Handled 1')->assertSuccessful();

        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->filter(fn ($e) => str_contains($e->command, 'telegram:poll'));
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
        $this->assertArrayNotHasKey('telegram:set-webhook', Artisan::all());
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('telegram.webhook'));
    }

    public function test_the_modal_creates_a_code_with_the_deep_link_and_turns_to_connected_by_itself(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(UsersIndex::class)
            ->call('edit', $this->user->id)
            ->assertSeeHtml('data-telegram-not-connected')
            ->call('connectTelegram');

        $code = TelegramLinkCode::query()->where('user_id', $this->user->id)->firstOrFail();
        $component->assertSet('linkCode', $code->code)
            ->assertSet('linkUrl', 'https://t.me/ajwa_bot?start='.$code->code)
            ->assertSeeHtml('wire:poll.3s="checkTelegram"')->assertSeeHtml('data-telegram-code');
        $this->assertTrue($code->expires_at->between(now()->addMinutes(14), now()->addMinutes(16)));

        // The person presses Start (the poller redeems the code); the next poll of the modal shows Connected.
        $this->assertSame('linked', app(TelegramLinks::class)->redeem($code->code, '123456789'));
        $component->call('checkTelegram')
            ->assertSet('linkCode', null)->assertSet('telegramChatId', '123456789')
            ->assertSeeHtml('data-telegram-linked')->assertSeeHtml('data-telegram-connected')->assertSee('••••••789');
    }

    public function test_without_a_bot_username_the_manual_instruction_is_shown(): void
    {
        config(['services.telegram.bot_username' => '']);

        $component = Livewire::actingAs(User::factory()->create())->test(UsersIndex::class)
            ->call('edit', $this->user->id)->call('connectTelegram');

        $code = TelegramLinkCode::query()->firstOrFail()->code;
        $component->assertSet('linkUrl', '')->assertSeeHtml('data-telegram-manual')->assertSee('/start '.$code);
    }

    public function test_an_expired_code_is_reported_in_the_modal(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(UsersIndex::class)
            ->call('edit', $this->user->id)->call('connectTelegram');
        TelegramLinkCode::query()->update(['expires_at' => now()->subMinute()]);

        $component->call('checkTelegram')->assertSet('linkCode', null)->assertSeeHtml('data-telegram-expired');
    }

    public function test_a_new_user_is_saved_and_gets_a_code_in_one_step(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(UsersIndex::class)
            ->call('create')
            ->set('name', 'Newcomer')->set('email', 'new@example.test')->set('password', 'secret-pass-1')
            ->call('saveAndConnect')->assertHasNoErrors();

        $new = User::query()->where('email', 'new@example.test')->firstOrFail();
        $component->assertSet('userId', $new->id)->assertSet('showModal', true);
        $this->assertSame(1, TelegramLinkCode::query()->where('user_id', $new->id)->count());

        Livewire::actingAs(User::factory()->create())->test(UsersIndex::class)
            ->call('create')->set('name', 'X')->set('email', 'bad')->call('saveAndConnect')->assertHasErrors('email');
    }

    public function test_saving_the_modal_does_not_wipe_a_chat_id_that_was_linked_meanwhile(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(UsersIndex::class)
            ->call('edit', $this->user->id)->call('connectTelegram');
        app(TelegramLinks::class)->redeem(TelegramLinkCode::query()->firstOrFail()->code, '555');

        $component->call('save')->assertHasNoErrors();

        $this->assertSame('555', $this->user->fresh()->telegram_chat_id);
    }

    public function test_disconnect_clears_the_chat_id_and_the_manual_field_still_works(): void
    {
        $this->user->update(['telegram_chat_id' => '123456789']);

        $component = Livewire::actingAs(User::factory()->create())->test(UsersIndex::class)
            ->call('edit', $this->user->id)->assertSeeHtml('data-telegram-connected')
            ->call('disconnectTelegram')->assertSeeHtml('data-telegram-not-connected');
        $this->assertNull($this->user->fresh()->telegram_chat_id);

        $component->set('telegramChatId', '42')->call('save');
        $this->assertSame('42', $this->user->fresh()->telegram_chat_id);
    }

    public function test_an_inactive_user_gets_no_code(): void
    {
        $this->user->update(['is_active' => false]);

        Livewire::actingAs(User::factory()->create())->test(UsersIndex::class)
            ->call('edit', $this->user->id)->call('connectTelegram')->assertHasErrors('telegram');

        $this->assertSame(0, TelegramLinkCode::query()->count());
    }
}
