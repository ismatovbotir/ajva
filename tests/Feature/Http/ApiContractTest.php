<?php

namespace Tests\Feature\Http;

use App\Jobs\ProcessLocalItemsBatch;
use App\Jobs\ProcessReceiptIngestion;
use App\Mcp\McpSettings;
use App\Models\Item;
use App\Models\Pos;
use App\Models\Receipt;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** HTTP contract of the machine APIs: status codes, JSON shape, auth, queue hand-off (complements tests/Feature/Api). */
class ApiContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mcp.token' => 'mcp-secret']);
    }

    private function batch(int $n, int $from = 1): array
    {
        $items = [];
        for ($i = $from; $i < $from + $n; $i++) {
            $items[] = [
                'id' => $i, 'mark' => "M$i", 'name' => "Item $i",
                'group' => ['id' => 1 + $i % 5, 'name' => 'Group'],
                'barcode' => ["B$i"],
                'qty' => [['shop' => ['id' => 1, 'name' => 'Shop 1'], 'value' => $i]],
                'price' => [['price' => ['id' => 2, 'name' => 'Retail'], 'value' => 1000 + $i]],
                'order' => [['shop' => ['id' => 1, 'name' => 'Shop 1'], 'min' => 1, 'max' => 10]],
            ];
        }

        return $items;
    }

    private function local(): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3']);
    }

    // ---- /api/items -------------------------------------------------------------

    public function test_a_full_batch_is_accepted_with_202_and_handed_to_the_queue(): void
    {
        Queue::fake();

        $this->local()->postJson('/api/items', $this->batch(200))->assertStatus(202);

        Queue::assertPushed(ProcessLocalItemsBatch::class, 1);
    }

    public function test_a_full_batch_is_really_processed_when_the_queue_runs(): void
    {
        $this->local()->postJson('/api/items', $this->batch(200))->assertStatus(202);   // sync queue in tests

        $this->assertSame(200, Item::query()->count());
        $this->assertDatabaseHas('shops', ['id' => 1]);
    }

    public function test_more_items_than_the_limit_are_rejected_before_anything_is_queued(): void
    {
        Queue::fake();

        $this->local()->postJson('/api/items', $this->batch(201))
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['items']]);

        Queue::assertNothingPushed();
    }

    public function test_an_object_body_instead_of_a_bare_array_is_rejected(): void
    {
        Queue::fake();

        // 1C must send a bare list; a wrapped {"items": [...]} body has no valid items.
        $this->local()->postJson('/api/items', ['items' => $this->batch(1)])->assertStatus(422);
        $this->local()->postJson('/api/items', [])->assertStatus(422)->assertJsonValidationErrors(['items']);
        Queue::assertNothingPushed();
    }

    public function test_invalid_nested_fields_return_a_message_per_field(): void
    {
        Queue::fake();
        $bad = $this->batch(1);
        $bad[0]['qty'] = [['shop' => ['id' => 'x'], 'value' => 'many']];
        $bad[0]['name'] = str_repeat('a', 101);

        $this->local()->postJson('/api/items', $bad)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.name', 'items.0.qty.0.shop.id', 'items.0.qty.0.value']);
        Queue::assertNothingPushed();
    }

    public function test_non_local_ips_get_403_json_even_with_a_valid_payload(): void
    {
        Queue::fake();

        foreach (['8.8.8.8', '172.32.0.1', '11.0.0.1'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/items', $this->batch(1))
                ->assertStatus(403)->assertJsonStructure(['message']);
        }
        foreach (['172.16.0.1', '172.31.255.255', '192.168.0.1'] as $ip) {
            $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/items', $this->batch(1))->assertStatus(202);
        }
    }

    public function test_the_items_endpoint_is_post_only(): void
    {
        $this->local()->getJson('/api/items')->assertStatus(405);
    }

    // ---- /api/receipts ----------------------------------------------------------

    private function receipt(Shop $shop, array $over = []): array
    {
        return array_merge([
            'shop' => $shop->id, 'pos' => 1, 'openDate' => '12.08.26', 'openTime' => '10:00:00',
            'number' => 'R-'.uniqid(), 'total' => 5000, 'status' => 'success', 'type' => 1,
            'user' => ['id' => 1, 'name' => 'Kassir'],
            'payments' => [['name' => 'cash', 'value' => 5000]],
            'positions' => [],
        ], $over);
    }

    public function test_a_valid_receipt_gets_202_and_is_queued(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();
        $item = Item::factory()->create();
        $pos = [['item' => ['id' => $item->id], 'qty' => 1, 'storno' => 0, 'sum' => 5000, 'sumWD' => 5000, 'totalSum' => 5000]];

        $this->withToken('test-pos-token')->postJson('/api/receipts', $this->receipt($shop, ['positions' => $pos]))
            ->assertStatus(202);

        Queue::assertPushed(ProcessReceiptIngestion::class, 1);
    }

    public function test_a_receipt_without_positions_or_payments_is_accepted_and_stored(): void
    {
        $shop = Shop::factory()->create();
        $data = $this->receipt($shop, ['status' => 'cancelled', 'total' => 0]);
        unset($data['positions'], $data['payments']);

        $this->withToken('test-pos-token')->postJson('/api/receipts', $data)->assertStatus(202);

        $this->assertDatabaseHas('receipts', ['number' => $data['number'], 'shop_id' => $shop->id]);
    }

    public function test_missing_wrong_malformed_or_empty_tokens_are_401_json_and_nothing_is_queued(): void
    {
        Queue::fake();
        $data = $this->receipt(Shop::factory()->create());

        $this->postJson('/api/receipts', $data)->assertStatus(401)->assertJson(['message' => 'Invalid or missing API token.']);
        $this->withToken('nope')->postJson('/api/receipts', $data)->assertStatus(401);
        $this->withHeader('Authorization', 'Basic dGVzdC1wb3MtdG9rZW4=')->postJson('/api/receipts', $data)->assertStatus(401);

        // The token is checked before the body: a wrong token never leaks validation details.
        $this->withToken('nope')->postJson('/api/receipts', [])->assertStatus(401)->assertJsonMissingPath('errors');

        // With the token unset on the server every request is refused, even with an empty bearer.
        config(['services.pos.token' => '']);
        $this->withToken('')->postJson('/api/receipts', $data)->assertStatus(401);
        Queue::assertNothingPushed();
    }

    public function test_validation_errors_are_422_with_field_messages(): void
    {
        Queue::fake();
        $shop = Shop::factory()->create();

        $this->withToken('test-pos-token')->postJson('/api/receipts', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shop', 'pos', 'openDate', 'openTime', 'number', 'total']);

        $this->withToken('test-pos-token')->postJson('/api/receipts', $this->receipt($shop, [
            'pos' => 0,
            'positions' => [['item' => ['id' => 999999], 'qty' => 'x']],
        ]))->assertStatus(422)->assertJsonValidationErrors(['pos', 'positions.0.item.id', 'positions.0.qty', 'positions.0.totalSum']);

        Queue::assertNothingPushed();
    }

    public function test_the_same_receipt_posted_twice_is_not_duplicated(): void
    {
        $shop = Shop::factory()->create();
        $data = $this->receipt($shop, ['number' => 'DUP-1']);

        $this->withToken('test-pos-token')->postJson('/api/receipts', $data)->assertStatus(202);
        $this->withToken('test-pos-token')->postJson('/api/receipts', $data)->assertStatus(202);

        $this->assertSame(1, Receipt::query()->where('number', 'DUP-1')->count());
    }

    public function test_several_pos_ids_of_one_shop_are_accepted_with_the_shared_token(): void
    {
        $shop = Shop::factory()->create();

        foreach ([1, 2, 3] as $posId) {
            $this->withToken('test-pos-token')->postJson('/api/receipts', $this->receipt($shop, ['pos' => $posId]))->assertStatus(202);
        }

        $this->assertSame(3, Pos::query()->where('shop_id', $shop->id)->count());
    }

    // ---- /api/mcp ---------------------------------------------------------------

    private function rpc(array $body, ?string $token = 'mcp-secret')
    {
        $r = $token === null ? $this : $this->withToken($token);

        return $r->postJson('/api/mcp', $body);
    }

    public function test_mcp_requires_a_token_and_can_be_switched_off(): void
    {
        $ping = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'];

        $this->rpc($ping, null)->assertStatus(401);
        $this->rpc($ping, 'wrong')->assertStatus(401);
        $this->rpc($ping)->assertOk()->assertJsonPath('jsonrpc', '2.0')->assertJsonPath('id', 1);

        app(McpSettings::class)->setEnabled(false);
        $this->rpc($ping)->assertStatus(403);
        $this->rpc($ping, 'wrong')->assertStatus(403);   // disabled is checked first: nothing is revealed
    }

    public function test_mcp_initialize_tools_list_and_call_shapes(): void
    {
        $this->rpc(['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'initialize', 'params' => ['protocolVersion' => '1999-01-01']])
            ->assertOk()
            ->assertJsonPath('id', 'a')
            ->assertJsonPath('result.protocolVersion', '2025-06-18')   // unknown version falls back to the newest supported
            ->assertJsonStructure(['result' => ['serverInfo' => ['name', 'version'], 'instructions']]);

        $tools = $this->rpc(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'])->assertOk()->json('result.tools');
        $this->assertNotEmpty($tools);
        foreach ($tools as $tool) {
            $this->assertArrayHasKey('name', $tool);
            $this->assertArrayHasKey('inputSchema', $tool);
            $this->assertTrue($tool['annotations']['readOnlyHint']);
        }

        $this->rpc(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => ['name' => 'list_shops']])
            ->assertOk()
            ->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.content.0.type', 'text');
    }

    public function test_mcp_error_cases(): void
    {
        $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'drop_tables']])
            ->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_shops', 'arguments' => 'x']])
            ->assertOk()->assertJsonPath('error.code', -32602);
        $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'nope'])->assertOk()->assertJsonPath('error.code', -32601);
        $this->rpc(['id' => 1, 'method' => 'ping'])->assertOk()->assertJsonPath('error.code', -32600);
        $this->call('POST', '/api/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer mcp-secret'], '{not json')
            ->assertOk()->assertJsonPath('error.code', -32700);
    }

    public function test_mcp_batch_requests_answer_as_a_list_and_notifications_get_202(): void
    {
        $this->rpc([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list'],
        ])->assertOk()->assertJsonCount(2)->assertJsonPath('0.id', 1)->assertJsonPath('1.id', 2);

        $this->rpc(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])->assertStatus(202);
    }

    public function test_a_disabled_tool_is_hidden_and_not_callable(): void
    {
        app(McpSettings::class)->setToolEnabled('list_shops', false);

        $names = collect($this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->json('result.tools'))->pluck('name');
        $this->assertFalse($names->contains('list_shops'));
        $this->rpc(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'list_shops']])
            ->assertOk()->assertJsonPath('error.code', -32602);
    }
}
