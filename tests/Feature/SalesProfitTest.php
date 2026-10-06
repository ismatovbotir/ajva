<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\SalesBoard;
use App\Models\Item;
use App\Models\ItemPrice;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\ReceiptPayment;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

class SalesProfitTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function cost(Item $item, float $value): void
    {
        $price = Price::query()->find(config('inventory.cost_price_id'))
            ?? Price::factory()->create(['id' => config('inventory.cost_price_id'), 'name' => 'Cost', 'is_sell' => false]);
        ItemPrice::factory()->create(['item_id' => $item->id, 'price_id' => $price->id, 'value' => $value]);
    }

    private function sale(Shop $shop, string $at, array $lines, array $attrs = []): Receipt
    {
        $receipt = Receipt::factory()->create(array_merge([
            'shop_id' => $shop->id, 'active' => true, 'sell' => true,
            'total' => array_sum(array_column($lines, 'total')), 'created_at' => $at,
        ], $attrs));
        foreach ($lines as $l) {
            ReceiptItem::factory()->create([
                'receipt_id' => $receipt->id, 'item_id' => $l['item']->id,
                'qty' => $l['qty'], 'total' => $l['total'], 'storno' => $l['storno'] ?? false,
            ]);
        }

        return $receipt;
    }

    public function test_profit_counts_only_cost_covered_lines_and_reports_the_gap(): void
    {
        Carbon::setTestNow('2026-10-06 15:30:00');
        $user = User::factory()->create();
        $shop = Shop::factory()->create();
        $a = Item::factory()->create(['name' => 'A']);   // cost 6
        $b = Item::factory()->create(['name' => 'B']);   // cost 0 -> not covered
        $c = Item::factory()->create(['name' => 'C']);   // no cost row -> not covered
        $d = Item::factory()->create(['name' => 'D']);   // cost 50, sold at a loss
        $this->cost($a, 6);
        $this->cost($b, 0);
        $this->cost($d, 50);

        $this->sale($shop, '2026-10-06 09:00:00', [
            ['item' => $a, 'qty' => 5, 'total' => 50],   // profit 20
            ['item' => $b, 'qty' => 1, 'total' => 25],
            ['item' => $c, 'qty' => 1, 'total' => 25],
            ['item' => $d, 'qty' => 2, 'total' => 80],   // profit -20
            ['item' => $a, 'qty' => 1, 'total' => 999, 'storno' => true], // ignored
        ]);
        // Yesterday before the same-time cut-off: profit 4 (qty 1, total 10, cost 6).
        $this->sale($shop, '2026-10-05 09:00:00', [['item' => $a, 'qty' => 1, 'total' => 10]]);
        // Yesterday after the cut-off and a refund/cancelled receipt: excluded.
        $this->sale($shop, '2026-10-05 20:00:00', [['item' => $a, 'qty' => 1, 'total' => 500]]);
        $this->sale($shop, '2026-10-06 10:00:00', [['item' => $a, 'qty' => 1, 'total' => 500]], ['sell' => false]);
        $this->sale($shop, '2026-10-06 10:00:00', [['item' => $a, 'qty' => 1, 'total' => 500]], ['active' => false]);

        $profit = Livewire::actingAs($user)->test(SalesBoard::class)->viewData('profit');

        $this->assertEquals(0.0, $profit['total']);            // 20 + (-20); B and C not counted
        $this->assertEquals(4.0, $profit['yesterday']);
        $this->assertEquals(2, $profit['missing_items']);      // B (cost 0) and C (no cost)
        $this->assertEquals(27.8, $profit['uncovered_percent']); // 50 of 180 revenue has no cost
        $this->assertCount(1, $profit['shops']);
        $this->assertEquals(0.0, $profit['shops'][0]['profit']);
    }

    public function test_item_profit_is_null_without_cost_and_negative_below_cost(): void
    {
        Carbon::setTestNow('2026-10-06 15:30:00');
        $user = User::factory()->create();
        $shop = Shop::factory()->create();
        $a = Item::factory()->create(['name' => 'Cheap']);
        $b = Item::factory()->create(['name' => 'NoCost']);
        $this->cost($a, 50);
        $this->sale($shop, '2026-10-06 09:00:00', [
            ['item' => $a, 'qty' => 2, 'total' => 80],
            ['item' => $b, 'qty' => 1, 'total' => 10],
        ]);

        $top = collect(Livewire::actingAs($user)->test(SalesBoard::class)->viewData('topItems')['all'])->keyBy('name');

        $this->assertSame('-20', $top['Cheap']['profit']);
        $this->assertTrue($top['Cheap']['profit_negative']);
        $this->assertNull($top['NoCost']['profit']);
    }

    public function test_average_check_payment_mix_refunds_and_seven_day_trend(): void
    {
        Carbon::setTestNow('2026-10-06 15:30:00');
        $user = User::factory()->create();
        $shop = Shop::factory()->create();
        $a = Item::factory()->create();
        $this->cost($a, 10);

        $r1 = $this->sale($shop, '2026-10-06 09:00:00', [['item' => $a, 'qty' => 1, 'total' => 100]]);
        $r2 = $this->sale($shop, '2026-10-06 10:00:00', [['item' => $a, 'qty' => 2, 'total' => 300]]);
        $this->sale($shop, '2026-10-06 11:00:00', [['item' => $a, 'qty' => 1, 'total' => 70]], ['sell' => false]);
        $this->sale($shop, '2026-09-29 11:00:00', [['item' => $a, 'qty' => 1, 'total' => 40]]); // outside 7 days
        $this->sale($shop, '2026-10-04 11:00:00', [['item' => $a, 'qty' => 1, 'total' => 60]]);
        ReceiptPayment::factory()->create(['receipt_id' => $r1->id, 'payment' => 'cash', 'value' => 100]);
        ReceiptPayment::factory()->create(['receipt_id' => $r2->id, 'payment' => 'card', 'value' => 300]);

        $c = Livewire::actingAs($user)->test(SalesBoard::class);

        $this->assertEquals(200.0, $c->viewData('totals')['avg']);
        $this->assertSame(1, $c->viewData('refunds')['count']);
        $this->assertEquals(70.0, $c->viewData('refunds')['sum']);

        $pay = collect($c->viewData('payments'))->keyBy('name');
        $this->assertEqualsWithDelta(75.0, $pay['card']['percent'], 0.01);
        $this->assertEqualsWithDelta(25.0, $pay['cash']['percent'], 0.01);

        $trend = collect($c->viewData('trend'));
        $this->assertCount(7, $trend);
        $this->assertSame('06.10', $trend->last()['label']);
        $this->assertEquals(400.0, $trend->last()['revenue']);
        $this->assertEquals(370.0, $trend->last()['profit']); // 100-10 + 300-20
        $this->assertEquals(60.0, $trend->firstWhere('label', '04.10')['revenue']);
        $this->assertNull($trend->firstWhere('label', '29.09'));
    }

    public function test_stock_value_at_cost_ignores_items_without_cost_and_reports_gap(): void
    {
        $user = User::factory()->create();
        $shop = Shop::factory()->create();
        $a = Item::factory()->create();
        $b = Item::factory()->create();
        $this->cost($a, 5);
        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $a->id, 'qty' => 10]); // 50
        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $b->id, 'qty' => 10]); // no cost

        Livewire::actingAs($user)->test(Dashboard::class)->assertViewHas('health', function (array $h) {
            $this->assertEquals(50.0, $h['value']);
            $this->assertSame(1, $h['missing_items']);
            $this->assertEquals(50.0, $h['uncovered_percent']);

            return true;
        });
    }

    public function test_dashboard_renders_new_sections(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')->assertOk()
            ->assertSee(__('Stock health'))
            ->assertSee(__('Pricing health'))
            ->assertSee(__('Profit by shop'))
            ->assertSee(__('Last 7 days'))
            ->assertSee(__('Entities'));
    }
}
