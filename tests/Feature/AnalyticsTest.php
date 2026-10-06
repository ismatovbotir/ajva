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
        $c = Item::factory()->create(['name' => 'Item C']); // no stock row, no sales: still listed
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

        // Every shop gets a tab, whether or not it sold anything.
        $tabIds = $component->viewData('tabs')->pluck('id');
        $this->assertTrue($tabIds->contains($shop->id) && $tabIds->contains($otherShop->id));

        $component->call('selectShop', $shop->id);
        $rows = $component->viewData('rows')->all();

        $this->assertCount(3, $rows); // every item is listed, sold or not
        $this->assertSame('Item C', $rows[0]['item']); // lowest remaining first
        $this->assertEquals(0, $rows[0]['stock']);
        $this->assertEquals(0, $rows[0]['sold']);
        $this->assertNull($rows[0]['min']);
        $this->assertSame('Item A', $rows[1]['item']);
        $this->assertEquals(3, $rows[1]['sold']);      // 4 sold - 1 refunded
        $this->assertEquals(7, $rows[1]['remaining']); // 10 - 3
        $this->assertEquals(2, $rows[1]['min']);
        $this->assertEquals(20, $rows[1]['max']);
        $this->assertSame('Item B', $rows[2]['item']);
        $this->assertEquals(45, $rows[2]['remaining']);

        // Header sorting: first click sorts ascending, second click flips it.
        $component->call('sort', 'item');
        $this->assertSame(['Item A', 'Item B', 'Item C'], array_column($component->viewData('rows')->all(), 'item'));
        $component->call('sort', 'item');
        $this->assertSame(['Item C', 'Item B', 'Item A'], array_column($component->viewData('rows')->all(), 'item'));
        $component->call('sort', 'bogus')->call('sort', 'remaining');
        $this->assertSame('remaining', $component->get('sortBy'));
        $this->assertSame('Item C', $component->viewData('rows')->first()['item']);

        // Clicking Item A's net-sold cell lists the day's successful receipts containing it.
        $component->call('showReceipts', $a->id);
        $modal = $component->viewData('modalReceipts');
        $this->assertEqualsCanonicalizing([$sale->id, $refund->id], $modal->pluck('id')->all());
        $component->assertSee($sale->number)->call('closeModal');
        $this->assertNull($component->viewData('modalReceipts'));
        $this->assertSame(10.0, Stock::where('item_id', $a->id)->first()->qty + 0.0); // stock untouched
    }
}
