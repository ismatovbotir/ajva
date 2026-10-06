<?php

namespace Tests\Feature\Api;

use App\Models\Item;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\ReceiptPayment;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mcp.token' => 'test-mcp-token']);
    }

    private function rpc(string $method, array $params = [], ?int $id = 1, string $token = 'test-mcp-token')
    {
        $body = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];
        if ($id !== null) {
            $body['id'] = $id;
        }

        return $this->withToken($token)->postJson('/api/mcp', $body);
    }

    private function toolData($response): array
    {
        return json_decode($response->json('result.content.0.text'), true);
    }

    public function test_requires_the_bearer_token(): void
    {
        $this->rpc('ping', token: 'wrong')->assertStatus(401);
        $this->postJson('/api/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->assertStatus(401);

        config(['mcp.token' => '']);
        $this->rpc('ping', token: '')->assertStatus(401);
    }

    public function test_initialize_and_tools_list(): void
    {
        $this->rpc('initialize', ['protocolVersion' => '2025-03-26'])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-03-26')
            ->assertJsonPath('result.capabilities.tools.listChanged', false);

        $names = collect($this->rpc('tools/list')->assertOk()->json('result.tools'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(
            ['list_shops', 'sales_summary', 'hourly_sales', 'top_items', 'stock_levels', 'get_receipt'],
            $names
        );

        // Notifications get an empty 202.
        $this->rpc('notifications/initialized', id: null)->assertStatus(202);
        $this->rpc('nope')->assertOk()->assertJsonPath('error.code', -32601);
    }

    public function test_sales_tools_report_successful_sales_only(): void
    {
        $shop = Shop::factory()->create(['name' => 'Chilonzor']);
        $item = Item::factory()->create(['name' => 'Non']);
        $mk = fn (array $a) => Receipt::factory()->create(array_merge(['shop_id' => $shop->id, 'created_at' => now()->setTime(10, 5)], $a));

        $sale = $mk(['total' => 100, 'active' => true, 'sell' => true]);
        $mk(['total' => 30, 'active' => true, 'sell' => false]);
        $mk(['total' => 999, 'active' => false, 'sell' => true]);
        ReceiptItem::factory()->create(['receipt_id' => $sale->id, 'item_id' => $item->id, 'qty' => 4, 'total' => 100, 'storno' => false]);
        ReceiptPayment::factory()->create(['receipt_id' => $sale->id, 'payment' => 'Naqd', 'value' => 100]);

        $summary = $this->toolData($this->rpc('tools/call', ['name' => 'sales_summary']));
        $this->assertEquals(100, $summary['totals']['sell_total']);
        $this->assertEquals(30, $summary['totals']['refund_total']);
        $this->assertSame(1, $summary['totals']['cancelled_count']);
        $this->assertEquals(70, $summary['totals']['net_total']);

        $hourly = $this->toolData($this->rpc('tools/call', ['name' => 'hourly_sales', 'arguments' => ['shop_id' => $shop->id]]));
        $this->assertSame('10', $hourly['hours'][0]['hour']);
        $this->assertEquals(100, $hourly['hours'][0]['total']);

        $top = $this->toolData($this->rpc('tools/call', ['name' => 'top_items']));
        $this->assertSame('Non', $top['items'][0]['item']);
        $this->assertEquals(4, $top['items'][0]['qty']);

        $receipt = $this->toolData($this->rpc('tools/call', ['name' => 'get_receipt', 'arguments' => ['receipt_id' => $sale->id]]));
        $this->assertSame('sell', $receipt['type']);
        $this->assertSame('Naqd', $receipt['payments'][0]['payment']);
    }

    public function test_admin_panel_settings_control_token_enable_flag_and_tools(): void
    {
        config(['mcp.token' => '']);
        $user = \App\Models\User::factory()->create();

        $component = \Livewire\Livewire::actingAs($user)->test(\App\Livewire\Settings\Mcp::class);

        // No token anywhere yet: the endpoint is closed.
        $this->rpc('ping', token: 'anything')->assertStatus(401);

        // Generating a token shows it once; only its hash is stored.
        $component->call('generateToken');
        $token = $component->get('newToken');
        $this->assertSame(48, strlen($token));
        $this->assertStringNotContainsString($token, (string) \Illuminate\Support\Facades\DB::table('settings')->value('value'));

        $this->rpc('ping', token: $token)->assertOk();
        $this->rpc('ping', token: 'anything')->assertStatus(401);

        // A regenerated token invalidates the old one.
        $component->call('generateToken');
        $this->rpc('ping', token: $token)->assertStatus(401);
        $token = $component->get('newToken');

        // Switching a tool off hides it and blocks calling it.
        $component->call('toggleTool', 'list_shops');
        $names = collect($this->rpc('tools/list', token: $token)->json('result.tools'))->pluck('name');
        $this->assertFalse($names->contains('list_shops'));
        $this->rpc('tools/call', ['name' => 'list_shops'], token: $token)->assertJsonPath('error.code', -32602);
        $component->call('toggleTool', 'not_a_tool'); // ignored
        $component->call('toggleTool', 'list_shops');
        $this->rpc('tools/call', ['name' => 'list_shops'], token: $token)->assertJsonPath('result.isError', false);

        // Disabling the server returns 403 even with a valid token.
        $component->call('toggleEnabled');
        $this->rpc('ping', token: $token)->assertStatus(403);
    }

    public function test_settings_page_renders_for_logged_in_users_only(): void
    {
        $this->get('/settings/mcp')->assertRedirect('/login');

        $this->actingAs(\App\Models\User::factory()->create())
            ->get('/settings/mcp')
            ->assertOk()
            ->assertSee('list_shops')
            ->assertSee(url('/api/mcp'));
    }

    public function test_bad_arguments_are_reported_as_tool_errors(): void
    {
        $response = $this->rpc('tools/call', ['name' => 'sales_summary', 'arguments' => ['date' => '2026-13-45']]);
        $response->assertOk()->assertJsonPath('result.isError', true);
        $this->assertStringContainsString('YYYY-MM-DD', $this->toolData($response)['error']);

        $this->rpc('tools/call', ['name' => 'get_receipt', 'arguments' => ['receipt_id' => 99999]])
            ->assertJsonPath('result.isError', true);
        $this->rpc('tools/call', ['name' => 'does_not_exist'])->assertJsonPath('error.code', -32602);
    }
}
