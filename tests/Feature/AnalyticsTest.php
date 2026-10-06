<?php

namespace Tests\Feature;

use App\Livewire\Analytics\Index;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_generate_nets_sales_and_refunds_against_stock_sorted_descending(): void
    {
        $user = User::factory()->create();
        $shop = Shop::factory()->create();
        $a = Item::factory()->create(['name' => 'Item A']);
        $b = Item::factory()->create(['name' => 'Item B']);
        Stock::query()->create(['shop_id' => $shop->id, 'item_id' => $a->id, 'qty' => 10]);
        Stock::query()->create(['shop_id' => $shop->id, 'item_id' => $b->id, 'qty' => 50]);

        $sale = Receipt::factory()->create(['shop_id' => $shop->id, 'active' => true, 'sell' => true, 'created_at' => now()]);
        $refund = Receipt::factory()->create(['shop_id' => $shop->id, 'active' => true, 'sell' => false, 'created_at' => now()]);
        $failed = Receipt::factory()->create(['shop_id' => $shop->id, 'active' => false, 'sell' => true, 'created_at' => now()]);

        foreach ([[$sale, $a, 4], [$refund, $a, 1], [$failed, $a, 99], [$sale, $b, 5]] as [$r, $i, $qty]) {
            ReceiptItem::factory()->create(['receipt_id' => $r->id, 'item_id' => $i->id, 'qty' => $qty, 'storno' => false]);
        }

        ItemOrderRule::query()->create(['shop_id' => $shop->id, 'item_id' => $a->id, 'min' => 2, 'max' => 20]);

        $otherShop = Shop::factory()->create();
        $sale2 = Receipt::factory()->create(['shop_id' => $otherShop->id, 'active' => true, 'sell' => true, 'created_at' => now()]);
        ReceiptItem::factory()->create(['receipt_id' => $sale2->id, 'item_id' => $a->id, 'qty' => 1, 'storno' => false]);

        $component = Livewire::actingAs($user)->test(Index::class)
            ->assertSee(__('Pick a date and press Generate.'))
            ->call('generate');

        $this->assertCount(2, $component->viewData('tabs'));

        $component->call('selectShop', $shop->id);
        $rows = $component->viewData('rows')->all();

        $this->assertCount(2, $rows); // only this shop's items
        $this->assertSame('Item A', $rows[0]['item']); // lowest remaining first
        $this->assertEquals(3, $rows[0]['sold']);      // 4 sold - 1 refunded
        $this->assertEquals(7, $rows[0]['remaining']); // 10 - 3
        $this->assertEquals(2, $rows[0]['min']);
        $this->assertEquals(20, $rows[0]['max']);
        $this->assertSame('Item B', $rows[1]['item']);
        $this->assertEquals(45, $rows[1]['remaining']);
        $this->assertNull($rows[1]['min']);

        // Clicking Item A's net-sold cell lists the day's successful receipts containing it.
        $component->call('showReceipts', $a->id);
        $modal = $component->viewData('modalReceipts');
        $this->assertEqualsCanonicalizing([$sale->id, $refund->id], $modal->pluck('id')->all());
        $component->assertSee($sale->number)->call('closeModal');
        $this->assertNull($component->viewData('modalReceipts'));
        $this->assertSame(10.0, Stock::where('item_id', $a->id)->first()->qty + 0.0); // stock untouched
    }
}
