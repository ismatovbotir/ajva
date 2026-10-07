<?php

namespace Tests\Feature;

use App\Livewire\Analytics\Index as AnalyticsIndex;
use App\Livewire\Dashboard;
use App\Livewire\Items\Show as ItemShow;
use App\Livewire\Monitor;
use App\Livewire\Shops\Index as ShopsIndex;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class StockHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sync(int $itemId, array $qtyByShop): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->postJson('/api/items', [[
                'id' => $itemId,
                'name' => 'Synced item',
                'qty' => collect($qtyByShop)->map(fn ($v, $shopId) => [
                    'shop' => ['id' => $shopId, 'name' => "Shop {$shopId}"],
                    'value' => $v,
                ])->values()->all(),
            ]])
            ->assertStatus(202);
    }

    /** One (item, shop) pair with yesterday = 100 (history) and today = 10 (current), min 20. */
    private function pairWithHistory(): array
    {
        $shop = Shop::factory()->create(['name' => 'Hist shop']);
        $item = Item::factory()->create(['name' => 'Hist item']);
        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => 100, 'stock_date' => now()->subDay()->toDateString()]);
        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => 10, 'stock_date' => now()->toDateString()]);
        ItemOrderRule::query()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'min' => 20, 'max' => 50]);

        return [$shop, $item];
    }

    public function test_same_day_resync_overwrites_that_days_row(): void
    {
        Carbon::setTestNow('2026-03-10 10:00:00');
        $this->sync(7, [1 => 5]);
        $this->sync(7, [1 => 8]);

        $this->assertSame(1, Stock::count());
        $this->assertDatabaseHas('stocks', ['item_id' => 7, 'shop_id' => 1, 'qty' => '8.000', 'stock_date' => '2026-03-10']);
    }

    public function test_next_day_sync_keeps_history_and_current_picks_newest(): void
    {
        Carbon::setTestNow('2026-03-10 10:00:00');
        $this->sync(7, [1 => 5]);
        Carbon::setTestNow('2026-03-11 10:00:00');
        $this->sync(7, [1 => 9]);

        $this->assertSame(2, Stock::count());
        $current = Stock::query()->current()->get();
        $this->assertCount(1, $current);
        $this->assertEquals(9, $current->first()->qty);
        $this->assertSame('2026-03-11', $current->first()->stock_date->toDateString());
    }

    public function test_pair_missing_from_latest_day_keeps_last_known_qty(): void
    {
        Carbon::setTestNow('2026-03-10 10:00:00');
        $this->sync(7, [1 => 5, 2 => 6]);
        Carbon::setTestNow('2026-03-11 10:00:00');
        $this->sync(7, [1 => 9]);

        $current = Stock::query()->current()->orderBy('shop_id')->get();
        $this->assertSame([1, 2], $current->pluck('shop_id')->all());
        $this->assertEquals([9, 6], $current->pluck('qty')->map(fn ($q) => (float) $q)->all());
    }

    public function test_creating_without_a_date_defaults_to_today(): void
    {
        $stock = Stock::query()->create(['shop_id' => Shop::factory()->create()->id, 'item_id' => Item::factory()->create()->id, 'qty' => 1]);

        $this->assertSame(now()->toDateString(), $stock->fresh()->stock_date->toDateString());
    }

    public function test_history_does_not_inflate_shops_list(): void
    {
        $this->pairWithHistory();

        $shops = Livewire::actingAs(User::factory()->create())->test(ShopsIndex::class)->viewData('shops');
        $this->assertSame(1, (int) $shops->first()->item_count);
        $this->assertEquals(10, $shops->first()->qty_total);
    }

    public function test_history_does_not_inflate_dashboard_figures(): void
    {
        $this->pairWithHistory();

        $c = Livewire::actingAs(User::factory()->create())->test(Dashboard::class);
        $this->assertEqualsWithDelta(10.0, $c->viewData('shopRows')[0]['total'], 0.001);
        $this->assertSame(1, $c->viewData('exceptions')['total']);
        $this->assertSame('10', $c->viewData('health')['units_label']);
    }

    public function test_history_does_not_inflate_analytics_rows(): void
    {
        [$shop] = $this->pairWithHistory();

        $rows = Livewire::actingAs(User::factory()->create())->test(AnalyticsIndex::class)
            ->call('generate')->call('selectShop', $shop->id)->viewData('rows')->all();

        $this->assertCount(1, $rows);
        $this->assertEquals(10, $rows[0]['stock']);
    }

    public function test_history_does_not_inflate_item_show(): void
    {
        [, $item] = $this->pairWithHistory();

        $stocks = Livewire::actingAs(User::factory()->create())->test(ItemShow::class, ['item' => $item])->viewData('stocks');
        $this->assertCount(1, $stocks);
        $this->assertEquals(10, $stocks->first()->qty);
    }

    public function test_history_does_not_inflate_monitor_alerts(): void
    {
        $this->pairWithHistory();

        $alerts = Livewire::actingAs(User::factory()->create())->test(Monitor::class)->viewData('alerts');
        $this->assertSame(1, $alerts['below_min']);
    }

    public function test_history_does_not_inflate_mcp_stock_levels(): void
    {
        [$shop] = $this->pairWithHistory();
        config(['mcp.token' => 'tok']);

        $response = $this->withToken('tok')->postJson('/api/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'stock_levels', 'arguments' => ['shop_id' => $shop->id]],
        ]);
        $data = json_decode($response->json('result.content.0.text'), true);

        $this->assertCount(1, $data['rows']);
        $this->assertEquals(10, $data['rows'][0]['qty']);
    }
}
