<?php

namespace Tests\Feature;

use App\Livewire\ReceiptAnalytics\Index;
use App\Models\Item;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReceiptAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private function sale(Shop $shop, float $total, float $discount = 0, array $items = []): Receipt
    {
        $r = Receipt::factory()->create([
            'shop_id' => $shop->id, 'active' => true, 'sell' => true,
            'total' => $total, 'discount' => $discount, 'created_at' => now(),
        ]);
        foreach ($items as $item) {
            ReceiptItem::factory()->create([
                'receipt_id' => $r->id, 'item_id' => $item->id, 'qty' => 1, 'storno' => false,
                'total' => $total / max(1, count($items)), 'discount' => $discount / max(1, count($items)),
            ]);
        }

        return $r;
    }

    public function test_page_requires_login_and_menu_links_to_it(): void
    {
        $this->get(route('receipt-analytics.index'))->assertRedirect();

        $this->actingAs(User::factory()->create())
            ->get(route('receipt-analytics.index'))
            ->assertOk()
            ->assertSee(__('Receipt analytics'));
    }

    public function test_generate_builds_kpis_discounts_big_receipts_and_pairs_per_selected_shops(): void
    {
        $user = User::factory()->create();
        $shop = Shop::factory()->create();
        $other = Shop::factory()->create();
        $a = Item::factory()->create(['name' => 'Alpha']);
        $b = Item::factory()->create(['name' => 'Beta']);

        for ($i = 0; $i < 30; $i++) {
            $this->sale($shop, 100, $i < 10 ? 10 : 0, [$a, $b]);
        }
        $this->sale($shop, 5000, 500, [$a]); // the big one
        $this->sale($other, 999, 0, [$a]);   // other shop: must be excluded
        // cancelled receipt with no items at all must be tolerated
        Receipt::factory()->create(['shop_id' => $shop->id, 'active' => false, 'total' => 0, 'created_at' => now()]);

        $c = Livewire::actingAs($user)->test(Index::class)
            ->set('shopIds', [$shop->id])
            ->call('generate')
            ->assertHasNoErrors();

        $report = $c->viewData('report');

        $this->assertSame(31, $report['kpi']['sale_count']);
        $this->assertEquals(8000.0, $report['kpi']['sale_sum']);
        $this->assertSame(1, $report['kpi']['cancelled_count']);
        $this->assertEquals(600.0, $report['kpi']['discount_sum']);
        $this->assertNotNull($report['big']['cut']);
        $this->assertSame(5000.0, $report['big']['top'][0]['total']);
        $this->assertNotEmpty($report['relations']['pairs']);
        $this->assertSame(30, $report['relations']['pairs'][0]['together']);
    }

    public function test_customers_with_and_without_loyalty_data_and_the_cashier_report(): void
    {
        $user = User::factory()->create();
        $shop = Shop::factory()->create(['name' => 'Main']);
        $mk = fn (float $total, ?array $aos, string $cashier, array $o = []) => Receipt::factory()->create(array_merge([
            'shop_id' => $shop->id, 'active' => true, 'sell' => true, 'total' => $total, 'discount' => 0,
            'aos' => $aos, 'cashier' => $cashier, 'created_at' => now(),
        ], $o));

        // Anna: 25 sales, 10 with a customer (aos filled), the rest anonymous (null / empty).
        for ($i = 0; $i < 10; $i++) {
            $mk(200, ['card' => '777'], 'Anna');
        }
        for ($i = 0; $i < 10; $i++) {
            $mk(100, null, 'Anna');
        }
        for ($i = 0; $i < 5; $i++) {
            $mk(100, [], 'Anna');
        }
        // Bob: 25 sales plus 10 refunds and 5 cancelled (far above the chain average).
        for ($i = 0; $i < 25; $i++) {
            $mk(100, null, 'Bob');
        }
        for ($i = 0; $i < 10; $i++) {
            $mk(100, null, 'Bob', ['sell' => false]);
        }
        for ($i = 0; $i < 5; $i++) {
            $mk(0, null, 'Bob', ['active' => false]);
        }
        // Cancelled receipt with no cashier name at all must not break grouping.
        $mk(0, null, '', ['active' => false]);

        $report = Livewire::actingAs($user)->test(Index::class)->call('generate')->viewData('report');

        $cu = $report['customers'];
        $this->assertSame(10, $cu['with']['count']);
        $this->assertSame(40, $cu['without']['count']);
        $this->assertEquals(20.0, $cu['with']['share']);
        $this->assertEquals(200.0, $cu['with']['avg_check']);
        $this->assertEquals(100.0, $cu['without']['avg_check']);
        $this->assertSame(10, $cu['by_shop'][0]['with_customer']);

        $cashiers = collect($report['cashiers'])->keyBy(fn ($c) => $c['cashier'] ?? '');
        $this->assertSame(25, $cashiers['Anna']['receipts']);
        $this->assertEquals(40.0, $cashiers['Anna']['customer_share']);
        $this->assertSame(10, $cashiers['Bob']['refunds']);
        $this->assertSame(5, $cashiers['Bob']['cancelled']);
        $this->assertSame([], $cashiers['Anna']['flags']);
        $this->assertContains('refunds', $cashiers['Bob']['flags']);
        $this->assertTrue($cashiers->has(''), 'a receipt without a cashier name still appears as Unknown');
    }

    public function test_receipt_links_open_the_receipt_in_a_modal_and_respect_shop_access(): void
    {
        $user = User::factory()->create();
        $shop = Shop::factory()->create();
        $item = Item::factory()->create(['name' => 'Alpha Item']);
        $receipt = $this->sale($shop, 777, 0, [$item]);

        Livewire::actingAs($user)->test(Index::class)
            ->call('openReceipt', $receipt->id)
            ->assertSet('receiptId', $receipt->id)
            ->assertSee('Alpha Item')
            ->assertSee($receipt->number)
            ->call('closeReceipt')
            ->assertSet('receiptId', null)
            ->assertDontSee('Alpha Item');

        // An operator without that shop gets a 404, not the receipt.
        $operator = User::factory()->create(['role' => 'operator']);
        Livewire::actingAs($operator)->test(Index::class)
            ->call('openReceipt', $receipt->id)
            ->assertNotFound();
    }

    public function test_empty_selection_means_all_shops_and_invalid_range_is_rejected(): void
    {
        $user = User::factory()->create();
        $shop = Shop::factory()->create();
        $this->sale($shop, 100);

        $c = Livewire::actingAs($user)->test(Index::class)->call('generate');
        $this->assertSame(1, $c->viewData('report')['kpi']['sale_count']);

        Livewire::actingAs($user)->test(Index::class)
            ->set('dateFrom', now()->toDateString())
            ->set('dateTo', now()->subDay()->toDateString())
            ->call('generate')
            ->assertHasErrors('dateTo');
    }
}
