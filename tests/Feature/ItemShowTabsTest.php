<?php

namespace Tests\Feature;

use App\Livewire\Items\Show;
use App\Models\Barcode;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\ItemPrice;
use App\Models\Price;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemShowTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_show_page_renders_with_all_four_tabs_read_only(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create(['name' => 'Widget']);

        $barcode = Barcode::factory()->create(['item_id' => $item->id, 'gtin' => '1234567890123']);

        $price = Price::factory()->create(['name' => 'Sale price']);
        ItemPrice::factory()->create(['item_id' => $item->id, 'price_id' => $price->id, 'value' => 15.50]);

        $shop = Shop::factory()->create(['name' => 'Main shop']);
        ItemOrderRule::factory()->create(['item_id' => $item->id, 'shop_id' => $shop->id, 'min' => 5, 'max' => 20]);
        Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $shop->id, 'qty' => 42]);

        $response = $this->actingAs($user)
            ->get("/items/{$item->id}")
            ->assertOk()
            ->assertSeeLivewire(Show::class)
            ->assertSee('Widget')
            ->assertSee(__('Barcodes'))
            ->assertSee(__('Item prices'))
            ->assertSee(__('Item order rules'))
            ->assertSee(__('Stock'))
            ->assertSee($barcode->gtin)
            ->assertSee($price->name)
            ->assertSee('15.50')
            ->assertSee($shop->name)
            ->assertSee('42');

        $response->assertDontSeeText(__('New barcode'));
        $response->assertDontSeeText(__('New item price'));
        $response->assertDontSeeText(__('New order rule'));
        $response->assertDontSeeText(__('New stock'));
        $response->assertDontSeeText(__('Edit'));
        $response->assertDontSeeText(__('Delete'));
        $response->assertDontSeeText(__('Save'));
    }

    public function test_guest_cannot_access_item_show_page(): void
    {
        $item = Item::factory()->create();

        $this->get("/items/{$item->id}")->assertRedirect('/login');
    }
}
