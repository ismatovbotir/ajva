<?php

namespace Tests\Feature;

use App\Livewire\Shops\Index;
use App\Livewire\Shops\Show;
use App\Models\Item;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shops_list_renders_item_count_and_total_qty(): void
    {
        $user = User::factory()->create();
        $shop = Shop::factory()->create(['name' => 'Main shop']);
        $itemA = Item::factory()->create(['name' => 'Item A']);
        $itemB = Item::factory()->create(['name' => 'Item B']);

        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemA->id, 'qty' => 10]);
        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $itemB->id, 'qty' => 5]);

        $this->actingAs($user)
            ->get('/shops')
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSee('Main shop')
            ->assertSee('2') // item_count
            ->assertSee('15'); // qty_total
    }

    public function test_shops_list_row_links_to_show_page(): void
    {
        $user = User::factory()->create();
        $shop = Shop::factory()->create(['name' => 'Main shop']);

        $this->actingAs($user)
            ->get('/shops')
            ->assertOk()
            ->assertSee(route('shops.show', $shop), false);
    }

    public function test_shop_show_page_lists_items_in_that_shop(): void
    {
        $user = User::factory()->create();
        $shop = Shop::factory()->create(['name' => 'Main shop']);
        $item = Item::factory()->create(['name' => 'Widget']);

        Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => 7]);

        $this->actingAs($user)
            ->get(route('shops.show', $shop))
            ->assertOk()
            ->assertSeeLivewire(Show::class)
            ->assertSee('Widget')
            ->assertSee('7');
    }

    public function test_guest_cannot_access_shops(): void
    {
        $shop = Shop::factory()->create();

        $this->get('/shops')->assertRedirect('/login');
        $this->get(route('shops.show', $shop))->assertRedirect('/login');
    }
}
