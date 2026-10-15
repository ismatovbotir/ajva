<?php

namespace Tests\Feature\Api;

use App\Enums\MonitorType;
use App\Enums\OrderStatus;
use App\Enums\OrderSyncStatus;
use App\Mcp\Tool;
use App\Models\Monitor;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Models\OrderLink;
use App\Models\OrderRule;
use App\Models\Pos;
use App\Models\Receipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsOrderWorld;
use Tests\TestCase;

/** The replenishment / order / monitor MCP tools and the same-time comparison of the sales tools. */
class McpOrderToolsTest extends TestCase
{
    use BuildsOrderWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mcp.token' => 'test-mcp-token']);
        app()->setLocale('en');
        $this->buildWorld();
    }

    protected function tearDown(): void
    {
        $this->tearDownWorld();
        parent::tearDown();
    }

    private function tool(string $tool, array $arguments = []): array
    {
        $response = $this->withToken('test-mcp-token')->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $arguments],
        ])->assertOk();

        $response->assertJsonPath('result.isError', false);

        return json_decode($response->json('result.content.0.text'), true);
    }

    private function order(array $attributes = []): Order
    {
        return Order::query()->create(array_merge([
            'shop_id' => $this->a->id,
            'status' => OrderStatus::Draft,
            'origin' => Order::ORIGIN_ORDERING_REPORT,
            'horizon_days' => 3,
        ], $attributes));
    }

    public function test_tools_are_listed_with_schemas_gemini_accepts(): void
    {
        $names = collect($this->withToken('test-mcp-token')->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'))->pluck('name')->all();
        foreach (['list_orders', 'get_order', 'replenishment_forecast', 'order_rules', 'list_monitors'] as $name) {
            $this->assertContains($name, $names);
        }

        // Every tool schema goes through AjvaAssistant::declaration (cleanSchema + shop name swap).
        $assistant = app(\App\Services\AjvaAssistant::class);
        $declaration = new \ReflectionMethod($assistant, 'declaration');
        foreach (config('mcp.tools') as $class) {
            /** @var Tool $tool */
            $tool = app($class);
            $declared = $declaration->invoke($assistant, $tool);
            $json = json_encode($declared);
            $this->assertStringNotContainsString('"pattern"', $json, $tool->name());
            $this->assertStringNotContainsString('"shop_id"', $json, $tool->name());
            $this->assertStringNotContainsString('"shop_ids"', $json, $tool->name());
            $this->assertStringNotContainsString('"maxLength"', $json, $tool->name());
        }
        $orders = json_encode($declaration->invoke($assistant, app(\App\Mcp\Tools\ListOrders::class)));
        $this->assertStringContainsString('shop_names', $orders);
        $this->assertStringContainsString('"enum"', $orders);
    }

    public function test_list_orders_filters_and_shows_sync_and_telegram_state(): void
    {
        $admin = User::factory()->create(['name' => 'Alice Admin']);
        $cola = $this->item('Cola', $this->a, 1, 5, 20);
        $water = $this->item('Water', $this->b, 1, 5, 20);

        $draft = $this->order(['created_by' => $admin->id, 'telegram_status' => 'failed', 'telegram_note' => 'Telegram: failed for Bob (chat 123456789 not found).']);
        $draft->items()->create(['item_id' => $cola->id, 'qty' => 4]);
        $draft->items()->create(['item_id' => $water->id, 'qty' => 6]);
        $failed = $this->order([
            'shop_id' => $this->b->id, 'status' => OrderStatus::Confirmed, 'confirmed_by' => $admin->id, 'confirmed_at' => now(),
            'sync_status' => OrderSyncStatus::Failed, 'sync_attempts' => 5, 'sync_error' => 'HTTP 500 from 1C',
        ]);

        $all = $this->tool('list_orders');
        $this->assertSame(2, $all['matching']);
        $this->assertEquals(['draft' => 1, 'confirmed' => 1], $all['by_status']);
        $first = $all['orders'][0];   // newest first
        $this->assertSame($failed->number(), $first['number']);
        $this->assertSame('Alice Admin', $first['confirmed_by']);
        $this->assertSame('failed', $first['sync_status']);
        $this->assertSame('HTTP 500 from 1C', $first['sync_error']);
        $this->assertSame('not_tried', $first['telegram_status']);

        $one = $this->tool('list_orders', ['status' => 'draft']);
        $this->assertCount(1, $one['orders']);
        $this->assertSame(2, $one['orders'][0]['lines']);
        $this->assertSame(10.0, (float) $one['orders'][0]['total_qty']);
        $this->assertSame('failed', $one['orders'][0]['telegram_status']);
        $this->assertSame('Alice Admin', $one['orders'][0]['created_by']);

        $this->assertCount(1, $this->tool('list_orders', ['sync_status' => 'failed'])['orders']);
        $this->assertCount(1, $this->tool('list_orders', ['telegram_status' => 'failed'])['orders']);
        $this->assertCount(1, $this->tool('list_orders', ['telegram_status' => 'not_tried'])['orders']);
        $this->assertCount(1, $this->tool('list_orders', ['shop_ids' => [$this->b->id]])['orders']);
        $this->assertCount(2, $this->tool('list_orders', ['from' => '2026-10-07', 'to' => '2026-10-07'])['orders']);
        $this->assertCount(0, $this->tool('list_orders', ['to' => '2026-10-01'])['orders']);
        $this->assertCount(1, $this->tool('list_orders', ['limit' => 1])['orders']);
    }

    public function test_get_order_shows_lines_and_history_but_never_secrets(): void
    {
        $operator = User::factory()->operator()->create(['name' => 'Olga Operator', 'telegram_chat_id' => '987654321']);
        $cola = $this->item('Cola', $this->a, 1, 5, 20);
        $order = $this->order(['telegram_status' => 'sent', 'telegram_note' => 'Telegram: link sent to chat 987654321.']);
        $order->items()->create(['item_id' => $cola->id, 'qty' => 8, 'suggested_qty' => 10, 'est' => 2, 'min' => 5, 'speed' => 1.5, 'cover_days' => 1.3]);
        $order->items()->create(['item_id' => $this->item('Water', $this->a, 1, 5, 20)->id, 'qty' => 3, 'added_manually' => true]);
        OrderLink::query()->create(['order_id' => $order->id, 'user_id' => $operator->id, 'token' => 'f47ac10b-58cc-4372-a567-0e02b2c3d479', 'created_at' => now()]);
        OrderEvent::record($order, $operator->id, OrderEvent::CHANNEL_LINK, OrderEvent::QTY_CHANGED, $cola->id, 10, 8);
        OrderEvent::record($order, null, OrderEvent::CHANNEL_SYSTEM, OrderEvent::CREATED, null, null, null, '2');

        $byNumber = $this->tool('get_order', ['order' => $order->number()]);
        $this->assertSame($byNumber, $this->tool('get_order', ['order' => (string) $order->id]));

        $this->assertSame('Alpha', $byNumber['order']['shop']);
        $this->assertCount(2, $byNumber['lines']);
        $this->assertSame(10.0, (float) $byNumber['lines'][0]['suggested_qty']);
        $this->assertTrue($byNumber['lines'][1]['added_manually']);
        $this->assertSame('Olga Operator', $byNumber['events'][0]['user']);
        $this->assertSame('link', $byNumber['events'][0]['channel']);
        $this->assertNull($byNumber['events'][1]['user']);

        $text = json_encode($byNumber);
        $this->assertStringNotContainsString('f47ac10b', $text);
        $this->assertStringNotContainsString('987654321', $text);
        $this->assertStringNotContainsString('token', strtolower($text));

        $this->withToken('test-mcp-token')->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'get_order', 'arguments' => ['order' => 'ORD-999999']],
        ])->assertJsonPath('result.isError', true);
    }

    public function test_replenishment_forecast_lists_needs_and_the_pick_list_with_shortage(): void
    {
        $cola = $this->item('Cola', $this->a, 0, 5, 20);
        $this->sellDaily($this->a, $cola, 4);
        $this->warehouseHas($cola, 6);   // less than the need: a shortage

        $data = $this->tool('replenishment_forecast', ['horizon_days' => 3]);

        $this->assertSame(3, $data['horizon_days']);
        $this->assertFalse($data['per_shop_rules']);
        $this->assertTrue($data['warehouse']['configured']);
        $this->assertSame(1, $data['summary']['lines']);
        $this->assertSame(1, $data['summary']['short_items']);
        $this->assertCount(1, $data['shops']);   // shops without needs are only counted
        $this->assertSame(1, $data['shops_without_needs']);
        $this->assertSame('Alpha', $data['shops'][0]['shop']);
        $line = $data['shops'][0]['items'][0];
        $this->assertSame('Cola', $line['item']);
        $this->assertSame('out', $line['level']);
        $this->assertGreaterThan(0, $line['order_qty']);
        $this->assertSame(6.0, (float) $line['can_send']);
        $this->assertSame(6.0, (float) $data['pick_list'][0]['available']);
        $this->assertGreaterThan(0, $data['pick_list'][0]['short']);
        $this->assertStringNotContainsString('cost', json_encode($data));

        // The main warehouse is never a recipient, even when asked for.
        $this->assertSame([], $this->tool('replenishment_forecast', ['shop_ids' => [$this->wh->id]])['shops']);
        $this->assertSame(0, $this->tool('replenishment_forecast', ['pick_n' => 0])['pick_list'] === [] ? 0 : 1);

        $rules = $this->tool('replenishment_forecast');
        $this->assertTrue($rules['per_shop_rules']);
    }

    public function test_order_rules_show_the_effective_rule_last_run_and_telegram_coverage_without_ids(): void
    {
        $this->defaultRule(['horizon_days' => 5, 'run_time' => '07:30', 'weekdays' => [1, 2]]);
        $own = OrderRule::query()->create(['shop_id' => $this->a->id, 'history_days' => 14]);
        OrderRule::claim($own->id, '2026-10-07');
        OrderRule::recordRun($own->id, 'skipped_draft', 'Skipped: draft ORD-000012 already exists.');

        $linked = User::factory()->operator()->create(['telegram_chat_id' => '555000111']);
        $plain = User::factory()->operator()->create(['telegram_chat_id' => null]);
        $inactive = User::factory()->operator()->create(['telegram_chat_id' => '777000222', 'is_active' => false]);
        foreach ([$linked, $plain, $inactive] as $u) {
            $u->shops()->attach($this->a->id);
        }

        $data = $this->tool('order_rules');
        $names = array_column($data['shops'], 'shop');
        $this->assertNotContains('Main warehouse', $names);

        $alpha = collect($data['shops'])->firstWhere('shop', 'Alpha');
        $this->assertTrue($alpha['auto_enabled']);
        $this->assertTrue($alpha['own_settings']);
        $this->assertSame(14, $alpha['history_days']);
        $this->assertSame(5, $alpha['horizon_days']);
        $this->assertSame('07:30', $alpha['run_time']);
        $this->assertSame([1, 2], $alpha['weekdays']);
        $this->assertSame('2026-10-07', $alpha['last_run_date']);
        $this->assertSame('skipped_draft', $alpha['last_run_status']);
        $this->assertSame(2, $alpha['responsible_users']);
        $this->assertSame(1, $alpha['with_telegram']);
        $this->assertSame(1, $alpha['without_telegram']);

        $beta = collect($data['shops'])->firstWhere('shop', 'Beta');
        $this->assertFalse($beta['own_settings']);
        $this->assertSame(28, $beta['history_days']);

        $text = json_encode($data);
        $this->assertStringNotContainsString('555000111', $text);
        $this->assertStringNotContainsString('@', $text);

        $this->assertCount(1, $this->tool('order_rules', ['shop_ids' => [$this->b->id]])['shops']);
    }

    public function test_list_monitors_never_returns_tokens_or_links(): void
    {
        $shop = $this->a;
        $exec = Monitor::factory()->withLink()->create(['name' => 'Office']);
        $exec->shops()->attach($shop->id);
        $custom = Monitor::factory()->type(MonitorType::Custom)->withProfit()->create([
            'name' => 'Builder screen',
            'layout' => [['id' => 'a', 'key' => 'sales_revenue'], ['id' => 'b', 'key' => 'top_items'], ['id' => 'c', 'key' => 'sales_revenue']],
        ]);
        Monitor::factory()->type(MonitorType::Custom)->create(['name' => 'Template', 'is_template' => true]);

        $data = $this->tool('list_monitors');
        $this->assertCount(3, $data['monitors']);

        $office = collect($data['monitors'])->firstWhere('name', 'Office');
        $this->assertSame(['Alpha'], $office['shops']);
        $this->assertFalse($office['all_shops']);
        $this->assertTrue($office['has_public_link']);
        $this->assertNull($office['widget_count']);

        $builder = collect($data['monitors'])->firstWhere('name', 'Builder screen');
        $this->assertTrue($builder['all_shops']);
        $this->assertTrue($builder['shows_profit']);
        $this->assertSame(3, $builder['widget_count']);
        $this->assertSame(['sales_revenue', 'top_items'], $builder['widget_keys']);

        $text = json_encode($data);
        $this->assertStringNotContainsString($exec->token, $text);
        $this->assertStringNotContainsString('/monitor/', $text);

        $this->assertCount(2, $this->tool('list_monitors', ['include_templates' => false])['monitors']);
        $this->assertCount(2, $this->tool('list_monitors', ['type' => 'custom'])['monitors']);
    }

    public function test_sales_tools_compare_with_yesterday_and_last_week_at_the_same_time(): void
    {
        $pos = Pos::query()->create(['shop_id' => $this->a->id, 'name' => 'Till A']);
        $make = fn (int $count, string $at, float $total) => Receipt::factory()->count($count)->create([
            'shop_id' => $this->a->id, 'pos_id' => $pos->id, 'total' => $total, 'created_at' => $at,
        ]);
        $make(12, '2026-10-07 10:00:00', 100);   // today so far (now = 12:00): 1 200
        $make(12, '2026-10-06 10:00:00', 200);   // yesterday before the cut: 2 400 -> -50 %
        $make(5, '2026-10-06 15:00:00', 999);    // yesterday after the cut: ignored
        $make(3, '2026-09-30 10:00:00', 100);    // same weekday last week: only 3 receipts -> no delta

        $summary = $this->tool('sales_summary', ['compare' => true]);
        $this->assertSame('12:00', $summary['comparison']['cut_time']);
        $this->assertSame('2026-10-06', $summary['comparison']['prev_day']);
        $this->assertSame('2026-09-30', $summary['comparison']['same_weekday_lw']);
        $c = $summary['totals']['comparison'];
        $this->assertSame(12, $c['prev_day']['receipts']);
        $this->assertSame(2400.0, (float) $c['prev_day']['total']);
        $this->assertEquals(-50.0, $c['delta_prev_day_pct']);
        $this->assertNull($c['delta_same_weekday_lw_pct']);
        $this->assertFalse($c['week_ago_has_no_receipts']);
        $this->assertEquals(-50.0, $summary['shops'][0]['comparison']['delta_prev_day_pct']);

        $this->assertArrayNotHasKey('comparison', $this->tool('sales_summary'));

        $hourly = $this->tool('hourly_sales', ['compare' => true])['comparison'];
        $this->assertSame(12, $hourly['prev_day']['receipts']);
        $this->assertEquals([['hour' => '10', 'receipts' => 12, 'total' => 2400.0]], $hourly['prev_day']['hours']);
        $this->assertEquals(-50.0, $hourly['delta_prev_day_pct']);
        $this->assertNull($hourly['delta_same_weekday_lw_pct']);

        // A past day is compared whole.
        $past = $this->tool('sales_summary', ['date' => '2026-10-06', 'compare' => true]);
        $this->assertSame('23:59', $past['comparison']['cut_time']);
        $this->assertSame(17, $past['totals']['sell_count']);
    }

    public function test_the_main_warehouse_is_flagged_and_left_out_of_sales(): void
    {
        $item = $this->item('Cola', $this->a, 3, 5, 20);
        $this->warehouseHas($item, 40);
        $pos = Pos::query()->create(['shop_id' => $this->wh->id, 'name' => 'WH till']);
        Receipt::factory()->create(['shop_id' => $this->wh->id, 'pos_id' => $pos->id, 'total' => 50, 'created_at' => now()]);

        $shops = $this->tool('list_shops');
        $this->assertSame($this->wh->id, $shops['warehouse_shop_id']);
        $this->assertTrue(collect($shops['shops'])->firstWhere('id', $this->wh->id)['is_warehouse']);

        $rows = $this->tool('stock_levels')['rows'];
        $this->assertTrue(collect($rows)->firstWhere('shop_id', $this->wh->id)['is_warehouse']);
        $this->assertFalse(collect($rows)->firstWhere('shop_id', $this->a->id)['is_warehouse']);

        $this->assertSame([], $this->tool('sales_summary')['shops']);
        $this->assertSame([], $this->tool('hourly_sales')['hours']);
        $this->assertCount(1, $this->tool('sales_summary', ['shop_id' => $this->wh->id])['shops']);
    }

    public function test_stock_levels_uses_the_latest_snapshot_only(): void
    {
        $item = $this->item('Cola', $this->a, 9, 5, 20);   // snapshots 2026-09-01 and today, both 9
        \App\Models\Stock::query()->where('item_id', $item->id)->where('stock_date', '2026-10-07')->update(['qty' => 2]);

        $rows = $this->tool('stock_levels', ['shop_id' => $this->a->id])['rows'];
        $this->assertCount(1, $rows);
        $this->assertSame(2.0, (float) $rows[0]['qty']);
        $this->assertTrue($rows[0]['below_min']);
    }

    public function test_new_tool_results_are_never_sent_to_gemini(): void
    {
        config(['services.gemini.key' => 'test-key', 'services.gemini.model' => 'gemini-test']);
        $admin = User::factory()->create();
        $cola = $this->item('Zanzibar Cola', $this->a, 0, 5, 20);
        $this->sellDaily($this->a, $cola, 4);
        $this->warehouseHas($cola, 3);
        $order = $this->order(['status' => OrderStatus::Confirmed, 'sync_status' => OrderSyncStatus::Failed, 'sync_error' => 'Secret failure text']);
        $order->items()->create(['item_id' => $this->item('Other Thing', $this->b, 1, 5, 20)->id, 'qty' => 12345]);
        Monitor::factory()->create(['name' => 'Hidden Monitor Name']);

        $plan = fn (string $tool, array $args) => ['candidates' => [['content' => ['role' => 'model', 'parts' => [['functionCall' => ['name' => $tool, 'args' => $args]]]]]]];
        \Illuminate\Support\Facades\Http::fakeSequence()
            ->push($plan('list_orders', ['shop_names' => ['alpha'], 'sync_status' => 'failed']))
            ->push($plan('replenishment_forecast', ['shop_names' => ['alpha']]))
            ->push(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'Done.']]]]]]);

        $component = \Livewire\Livewire::actingAs($admin)->test(\App\Livewire\AjvaAI\Index::class)
            ->set('question', 'failed orders of alpha')->call('ask')
            ->assertSet('error', null)
            ->assertSee(__(':n orders failed to sync to 1C: :names. Open each one and use Retry sync once the cause is fixed.', ['n' => 1, 'names' => $order->number()]));

        $component->set('question', 'what does alpha need?')->call('ask')->assertSee('Zanzibar Cola');
        $component->set('question', 'thanks')->call('ask');

        $body = json_encode(\Illuminate\Support\Facades\Http::recorded()->last()[0]->data(), JSON_UNESCAPED_UNICODE);
        foreach (['Zanzibar Cola', 'Alpha', 'Secret failure text', '12345', $order->number(), 'Hidden Monitor Name'] as $secret) {
            $this->assertStringNotContainsString($secret, $body, $secret);
        }
        $this->assertStringContainsString('executed locally', $body);
        $this->assertStringContainsString('list_orders', $body);
    }

    public function test_the_advisor_notes_failed_syncs_unsent_drafts_and_a_sales_drop(): void
    {
        $notes = \App\Support\LocalAdvisor::notes('list_orders', ['orders' => [
            ['number' => 'ORD-000001', 'status' => 'confirmed', 'sync_status' => 'failed', 'telegram_status' => 'not_tried'],
            ['number' => 'ORD-000002', 'status' => 'draft', 'sync_status' => 'not_queued', 'telegram_status' => 'no_recipients'],
            ['number' => 'ORD-000003', 'status' => 'draft', 'sync_status' => 'not_queued', 'telegram_status' => 'sent'],
        ]]);
        $this->assertCount(2, $notes);

        $this->assertSame([], \App\Support\LocalAdvisor::notes('list_orders', ['orders' => []]));

        $sales = \App\Support\LocalAdvisor::notes('sales_summary', ['totals' => [
            'sell_count' => 30, 'sell_total' => 100.0, 'refund_count' => 0, 'refund_total' => 0.0, 'cancelled_count' => 0,
            'comparison' => ['delta_prev_day_pct' => -30.0, 'delta_same_weekday_lw_pct' => null],
        ]]);
        $this->assertCount(1, $sales);
        $this->assertSame('warn', $sales[0]['level']);
    }
}
