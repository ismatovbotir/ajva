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
use Livewire\Livewire;
use Tests\TestCase;

class ItemShowTabsTest extends TestCase
{
    use RefreshDatabase;

    public function test_item_show_page_renders_with_all_four_tabs(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create(['name' => 'Widget']);

        $this->actingAs($user)
            ->get("/items/{$item->id}")
            ->assertOk()
            ->assertSeeLivewire(Show::class)
            ->assertSee('Widget')
            ->assertSee(__('Barcodes'))
            ->assertSee(__('Item prices'))
            ->assertSee(__('Item order rules'))
            ->assertSee(__('Stock'));
    }

    // --- Barcodes tab ---

    public function test_barcode_can_be_added_edited_and_deleted(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(Show::class, ['item' => $item])
            ->call('createBarcode')
            ->set('gtin', '1234567890123')
            ->call('saveBarcode')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('barcodes', ['item_id' => $item->id, 'gtin' => '1234567890123']);

        $barcode = Barcode::query()->where('item_id', $item->id)->firstOrFail();

        $component
            ->call('editBarcode', $barcode->id)
            ->set('gtin', '9999999999999')
            ->call('saveBarcode')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('barcodes', ['id' => $barcode->id, 'gtin' => '9999999999999']);

        $component->call('deleteBarcode', $barcode->id);

        $this->assertDatabaseMissing('barcodes', ['id' => $barcode->id]);
    }

    public function test_duplicate_barcode_across_items_surfaces_friendly_validation_error(): void
    {
        $user = User::factory()->create();
        $itemA = Item::factory()->create();
        $itemB = Item::factory()->create();

        Barcode::factory()->create(['item_id' => $itemA->id, 'gtin' => '1111111111111']);

        Livewire::actingAs($user)
            ->test(Show::class, ['item' => $itemB])
            ->call('createBarcode')
            ->set('gtin', '1111111111111')
            ->call('saveBarcode')
            ->assertHasErrors(['gtin' => 'unique']);

        $this->assertSame(1, Barcode::query()->where('gtin', '1111111111111')->count());
    }

    // --- Item prices tab ---

    public function test_item_price_can_be_added_edited_and_deleted(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();
        $price = Price::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(Show::class, ['item' => $item])
            ->call('createItemPrice')
            ->set('price_id', $price->id)
            ->set('value', '15.50')
            ->call('saveItemPrice')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('item_prices', ['item_id' => $item->id, 'price_id' => $price->id, 'value' => 15.50]);

        $itemPrice = ItemPrice::query()->where('item_id', $item->id)->firstOrFail();

        $component
            ->call('editItemPrice', $itemPrice->id)
            ->set('value', '20.00')
            ->call('saveItemPrice')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('item_prices', ['id' => $itemPrice->id, 'value' => 20.00]);

        $component->call('deleteItemPrice', $itemPrice->id);

        $this->assertDatabaseMissing('item_prices', ['id' => $itemPrice->id]);
    }

    public function test_duplicate_item_price_for_same_price_type_surfaces_friendly_validation_error(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();
        $price = Price::factory()->create();

        ItemPrice::factory()->create(['item_id' => $item->id, 'price_id' => $price->id]);

        Livewire::actingAs($user)
            ->test(Show::class, ['item' => $item])
            ->call('createItemPrice')
            ->set('price_id', $price->id)
            ->set('value', '5')
            ->call('saveItemPrice')
            ->assertHasErrors(['price_id' => 'unique']);

        $this->assertSame(1, ItemPrice::query()->where('item_id', $item->id)->where('price_id', $price->id)->count());
    }

    // --- Item order rules tab ---

    public function test_order_rule_can_be_added_edited_and_deleted(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();
        $shop = Shop::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(Show::class, ['item' => $item])
            ->call('createOrderRule')
            ->set('shop_id', $shop->id)
            ->set('min', '5')
            ->set('max', '20')
            ->call('saveOrderRule')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('item_order_rules', ['item_id' => $item->id, 'shop_id' => $shop->id, 'min' => 5, 'max' => 20]);

        $orderRule = ItemOrderRule::query()->where('item_id', $item->id)->firstOrFail();

        $component
            ->call('editOrderRule', $orderRule->id)
            ->set('max', '30')
            ->call('saveOrderRule')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('item_order_rules', ['id' => $orderRule->id, 'max' => 30]);

        $component->call('deleteOrderRule', $orderRule->id);

        $this->assertDatabaseMissing('item_order_rules', ['id' => $orderRule->id]);
    }

    public function test_duplicate_order_rule_for_same_shop_surfaces_friendly_validation_error(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();
        $shop = Shop::factory()->create();

        ItemOrderRule::factory()->create(['item_id' => $item->id, 'shop_id' => $shop->id]);

        Livewire::actingAs($user)
            ->test(Show::class, ['item' => $item])
            ->call('createOrderRule')
            ->set('shop_id', $shop->id)
            ->set('min', '1')
            ->set('max', '2')
            ->call('saveOrderRule')
            ->assertHasErrors(['shop_id' => 'unique']);

        $this->assertSame(1, ItemOrderRule::query()->where('item_id', $item->id)->where('shop_id', $shop->id)->count());
    }

    // --- Stock tab ---

    public function test_stock_can_be_added_edited_and_deleted(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();
        $shop = Shop::factory()->create();

        $component = Livewire::actingAs($user)
            ->test(Show::class, ['item' => $item])
            ->call('createStock')
            ->set('stock_shop_id', $shop->id)
            ->set('stock_qty', '15.5')
            ->call('saveStock')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stocks', ['item_id' => $item->id, 'shop_id' => $shop->id, 'qty' => 15.5]);

        $stock = Stock::query()->where('item_id', $item->id)->firstOrFail();

        $component
            ->call('editStock', $stock->id)
            ->set('stock_qty', '99')
            ->call('saveStock')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'qty' => 99]);

        $component->call('deleteStock', $stock->id);

        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    public function test_duplicate_stock_for_same_shop_surfaces_friendly_validation_error(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();
        $shop = Shop::factory()->create();

        Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $shop->id]);

        Livewire::actingAs($user)
            ->test(Show::class, ['item' => $item])
            ->call('createStock')
            ->set('stock_shop_id', $shop->id)
            ->set('stock_qty', '3')
            ->call('saveStock')
            ->assertHasErrors(['stock_shop_id' => 'unique']);

        $this->assertSame(1, Stock::query()->where('item_id', $item->id)->where('shop_id', $shop->id)->count());
    }

    public function test_editing_a_stock_record_from_the_item_page_does_not_trip_the_duplicate_check_against_itself(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();
        $stock = Stock::factory()->create(['item_id' => $item->id]);

        Livewire::actingAs($user)
            ->test(Show::class, ['item' => $item])
            ->call('editStock', $stock->id)
            ->set('stock_qty', '42')
            ->call('saveStock')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'qty' => 42]);
    }
}
