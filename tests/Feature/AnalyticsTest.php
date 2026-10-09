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
use Illuminate\Support\Facades\DB;
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

        // Only items sold in this shop by successful sale receipts: Item C never sold, so it is not listed.
        $this->assertCount(2, $rows);
        $this->assertSame('Item A', $rows[0]['item']); // lowest remaining first
        $this->assertEquals(4, $rows[0]['sold']);      // refund and cancelled receipts are not counted
        $this->assertEquals(6, $rows[0]['remaining']); // 10 - 4
        $this->assertEquals(2, $rows[0]['min']);
        $this->assertEquals(20, $rows[0]['max']);
        $this->assertSame('Item B', $rows[1]['item']);
        $this->assertEquals(45, $rows[1]['remaining']);

        // Header sorting: first click sorts ascending, second click flips it.
        $component->call('sort', 'item');
        $this->assertSame(['Item A', 'Item B'], array_column($component->viewData('rows')->all(), 'item'));
        $component->call('sort', 'item');
        $this->assertSame(['Item B', 'Item A'], array_column($component->viewData('rows')->all(), 'item'));
        $component->call('sort', 'bogus')->call('sort', 'remaining');
        $this->assertSame('remaining', $component->get('sortBy'));
        $this->assertSame('Item A', $component->viewData('rows')->first()['item']);

        // Clicking Item A's sold cell lists the day's successful sale receipts containing it (not the refund).
        $component->call('showReceipts', $a->id);
        $modal = $component->viewData('modalReceipts');
        $this->assertEqualsCanonicalizing([$sale->id], $modal->pluck('id')->all());
        $component->assertSee($sale->number)->call('closeModal');
        $this->assertNull($component->viewData('modalReceipts'));
        $this->assertSame(10.0, Stock::where('item_id', $a->id)->first()->qty + 0.0); // stock untouched
    }

    public function test_generate_prebuilds_every_shops_table_so_tab_switches_never_recompute(): void
    {
        $user = User::factory()->create();
        $a = Shop::factory()->create();
        $b = Shop::factory()->create();
        $item = Item::factory()->create(['name' => 'Item X']);
        foreach ([$a, $b] as $shop) {
            $receipt = Receipt::factory()->create(['shop_id' => $shop->id, 'active' => true, 'sell' => true, 'created_at' => now()]);
            ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'qty' => 2, 'storno' => false]);
        }

        $component = Livewire::actingAs($user)->test(Index::class)->call('generate');

        // After Generate, switching tabs and sorting must be served from the cached report.
        DB::enableQueryLog();
        $component->call('selectShop', $b->id)->call('sort', 'item')->call('selectShop', $a->id);
        $queries = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();

        $this->assertFalse(
            $queries->contains(fn ($q) => str_contains($q, 'receipt_items')),
            'Tab switches/sorting must not re-run the sales aggregate'
        );
        $this->assertEquals(2, $component->viewData('rows')->firstWhere('item', 'Item X')['sold']);
    }

    public function test_the_generating_modal_is_wired_to_the_generate_action(): void
    {
        Livewire::actingAs(User::factory()->create())->test(Index::class)
            ->assertSeeHtml('wire:target="generate" style="display: none;"')   // hidden until Generate runs
            ->assertSee(__('Generating report…'))
            ->assertSeeHtml('indeterminate-bar');
    }
}
