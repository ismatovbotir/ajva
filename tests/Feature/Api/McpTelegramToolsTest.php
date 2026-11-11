<?php

namespace Tests\Feature\Api;

use App\Mcp\McpSettings;
use App\Models\Telegram;
use App\Models\TelegramMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpTelegramToolsTest extends TestCase
{
    use RefreshDatabase;

    private Telegram $ann;

    private Telegram $bob;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mcp.token' => 'test-mcp-token']);
        $this->ann = Telegram::query()->create(['tg_id' => 555111222, 'username' => 'ann', 'first_name' => 'Ann', 'last_name' => 'Lee']);
        $this->bob = Telegram::query()->create(['tg_id' => 777333444, 'first_name' => 'Bob']);
        User::factory()->create(['name' => 'Ann CRM', 'telegram_chat_id' => '555111222']);
    }

    private function tool(string $tool, array $arguments = [], bool $error = false): array
    {
        $response = $this->withToken('test-mcp-token')->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments],
        ])->assertOk();
        $response->assertJsonPath('result.isError', $error);

        return json_decode($response->json('result.content.0.text'), true) ?? [];
    }

    private function msg(Telegram $who, string $direction, string $text, string $at, array $extra = []): TelegramMessage
    {
        return TelegramMessage::query()->create(array_merge([
            'telegram_id' => $who->id, 'direction' => $direction, 'chat_id' => $who->tg_id, 'from_id' => $direction === 'in' ? $who->tg_id : null,
            'type' => 'text', 'text' => $text, 'sent_at' => $at,
        ], $extra));
    }

    public function test_tools_are_listed_and_can_be_switched_off(): void
    {
        $names = collect($this->withToken('test-mcp-token')->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'))->pluck('name')->all();
        foreach (['list_telegram_messages', 'get_telegram_message', 'telegram_inbox_analysis'] as $n) {
            $this->assertContains($n, $names);
        }

        app(McpSettings::class)->setToolEnabled('list_telegram_messages', false);
        $this->withToken('test-mcp-token')->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_telegram_messages', 'arguments' => (object) []],
        ])->assertJsonPath('error.code', -32602);
    }

    public function test_list_defaults_to_received_hides_ids_and_truncates(): void
    {
        $this->msg($this->ann, 'in', str_repeat('a', 600).' code 1234567890', now()->subHour()->toDateTimeString());
        $this->msg($this->bob, 'in', 'need price 100% off_', now()->subMinutes(30)->toDateTimeString());
        $this->msg($this->ann, 'out', 'order msg', now()->toDateTimeString(), ['order_id' => null, 'status' => 'failed', 'error' => 'chat 555111222 not found']);

        $res = $this->tool('list_telegram_messages');
        $this->assertSame(2, $res['matching']);
        $this->assertSame('in', $res['messages'][0]['direction']);
        $raw = json_encode($res);
        foreach (['555111222', '777333444', 'chat_id', 'from_id', 'tg_id'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
        $ann = collect($res['messages'])->firstWhere('person.name', 'Ann Lee');
        $this->assertTrue($ann['text_truncated']);
        $this->assertSame(500, mb_strlen($ann['text']));
        $this->assertSame('Ann CRM', $ann['person']['linked_user']['name']);
        $this->assertSame('ann', $ann['person']['username']);

        $both = $this->tool('list_telegram_messages', ['direction' => 'both']);
        $this->assertSame(3, $both['matching']);
        $out = collect($both['messages'])->firstWhere('direction', 'out');
        $this->assertSame('failed', $out['status']);
        $this->assertStringNotContainsString('555111222', $out['error']);
    }

    public function test_list_filters_search_unanswered_linked_and_cursor(): void
    {
        $this->msg($this->ann, 'in', 'where is my order', now()->subHours(5)->toDateTimeString());
        $this->msg($this->ann, 'out', 'on the way', now()->subHours(4)->toDateTimeString());
        $this->msg($this->bob, 'in', 'price 100% sale_x', now()->subHours(3)->toDateTimeString());
        $this->msg($this->bob, 'in', 'hello', now()->subHours(2)->toDateTimeString(), ['type' => 'photo']);

        $this->assertSame(2, $this->tool('list_telegram_messages', ['only_unanswered' => true])['matching']);
        $this->assertSame(1, $this->tool('list_telegram_messages', ['linked_user' => true])['matching']);
        $this->assertSame(2, $this->tool('list_telegram_messages', ['linked_user' => false])['matching']);
        $this->assertSame(1, $this->tool('list_telegram_messages', ['search' => '100%'])['matching']);
        $this->assertSame(0, $this->tool('list_telegram_messages', ['search' => 'sale-x'])['matching']);
        $this->assertSame(1, $this->tool('list_telegram_messages', ['type' => 'photo'])['matching']);
        $this->assertSame(2, $this->tool('list_telegram_messages', ['person_id' => $this->bob->id])['matching']);
        $this->assertSame(0, $this->tool('list_telegram_messages', ['from' => now()->addDay()->toDateString()])['matching']);

        $page = $this->tool('list_telegram_messages', ['limit' => 2]);
        $this->assertSame(2, $page['returned']);
        $this->assertNotNull($page['next_cursor']);
        $next = $this->tool('list_telegram_messages', ['limit' => 2, 'before_id' => $page['next_cursor']]);
        $this->assertSame(1, $next['returned']);
        $this->assertNull($next['next_cursor']);

        $this->tool('list_telegram_messages', ['limit' => 500], true);
    }

    public function test_get_message_returns_context_of_the_same_person(): void
    {
        $ids = [];
        foreach (range(1, 8) as $i) {
            $ids[$i] = $this->msg($this->ann, 'in', "m{$i}", now()->subHours(20 - $i)->toDateTimeString())->id;
        }
        $this->msg($this->bob, 'in', 'other person', now()->subHours(12)->toDateTimeString());

        $res = $this->tool('get_telegram_message', ['id' => $ids[4]]);
        $this->assertSame('m4', $res['message']['text']);
        $this->assertSame(['m1', 'm2', 'm3'], array_column($res['earlier'], 'text'));
        $this->assertSame(['m5', 'm6', 'm7', 'm8'], array_column($res['later'], 'text'));

        $this->assertCount(5, $this->tool('get_telegram_message', ['id' => $ids[8]])['earlier']);
        $this->tool('get_telegram_message', ['id' => 99999], true);
    }

    public function test_inbox_analysis(): void
    {
        $this->msg($this->ann, 'in', 'narx qancha? narx', now()->subDays(3)->setTime(10, 5)->toDateTimeString());
        $this->msg($this->ann, 'out', 'reply', now()->subDays(3)->setTime(10, 35)->toDateTimeString());
        $this->msg($this->ann, 'in', '/start', now()->subDays(2)->setTime(11, 0)->toDateTimeString());
        $this->msg($this->bob, 'in', 'narx 123456789', now()->subDay()->setTime(9, 0)->toDateTimeString());
        foreach (range(1, 3) as $i) {
            $this->msg($this->bob, 'in', 'Salom  yordam', now()->subDay()->setTime(9, $i)->toDateTimeString());
        }
        $this->msg($this->bob, 'in', 'pic', now()->subDay()->setTime(9, 30)->toDateTimeString(), ['type' => 'photo', 'text' => null]);
        // Order notifications: not replies; one failed.
        $order = \App\Models\Order::query()->create(['shop_id' => \App\Models\Shop::factory()->create()->id, 'status' => \App\Enums\OrderStatus::Draft, 'origin' => 'ordering_report', 'horizon_days' => 3]);
        $this->msg($this->bob, 'out', 'draft', now()->subHours(2)->toDateTimeString(), ['order_id' => $order->id, 'status' => 'failed', 'error' => 'chat not found']);
        $this->msg($this->ann, 'out', 'draft', now()->subHours(2)->toDateTimeString(), ['order_id' => $order->id, 'status' => 'sent']);

        $res = $this->tool('telegram_inbox_analysis', ['days' => 7]);

        $this->assertSame(7, $res['received_per_day'] ? count($res['received_per_day']) : 0);
        $this->assertSame(7, $res['totals']['received']);
        $this->assertSame(3, $res['totals']['sent']);
        $this->assertSame(7, array_sum(array_column($res['received_per_day'], 'count')));
        $this->assertSame(24, count($res['received_per_hour']));
        $this->assertSame(7, array_sum(array_column($res['received_per_weekday'], 'count')));
        $this->assertSame('Monday', $res['received_per_weekday'][0]['weekday']);
        $this->assertSame('text', $res['types'][0]['type']);

        $s = $res['senders'];
        $this->assertSame(2, $s['distinct']);
        $this->assertSame(1, $s['linked_to_crm']);
        $this->assertSame(1, $s['unlinked']);
        $this->assertSame(5, $s['unlinked_messages']);
        $this->assertSame('Bob', $s['top'][0]['name']);

        $r = $res['response'];
        // Ann's "/start" and all of Bob's messages have no reply; Ann's first message was answered after 30 minutes.
        $this->assertSame(1, $r['answered_measured']);
        $this->assertEquals(30, $r['median_first_reply_minutes']);
        $this->assertSame(6, $r['unanswered']);

        $t = $res['text'];
        $this->assertSame(6, $t['text_messages']);
        $this->assertSame(1, $t['command_messages']);
        $this->assertContains('narx', array_column($t['top_keywords'], 'word'));
        $this->assertNotContains('123456789', array_column($t['top_keywords'], 'word'));
        $this->assertSame('salom yordam', $t['top_repeated_messages'][0]['text']);
        $this->assertSame(3, $t['top_repeated_messages'][0]['count']);
        $this->assertFalse($t['sampled']);

        $d = $res['order_delivery'];
        $this->assertSame(1, $d['sent']);
        $this->assertSame(1, $d['failed']);
        $this->assertSame('chat not found', $d['top_errors'][0]['error']);
        $this->assertNotEmpty($res['notes']);

        $this->tool('telegram_inbox_analysis', ['days' => 200], true);
        $this->assertStringNotContainsString('555111222', json_encode($res));
    }
}
