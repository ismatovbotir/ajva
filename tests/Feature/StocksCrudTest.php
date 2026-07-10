<?php

namespace Tests\Feature;

use App\Livewire\Stocks\Index;
use App\Models\Item;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StocksCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_stocks_list_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        $stock = Stock::factory()->create();

        $this->actingAs($user)
            ->get('/stocks')
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSee($stock->item->name);
    }

    public function test_stock_can_be_created(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();
        $shop = Shop::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('create')
            ->set('item_id', $item->id)
            ->set('shop_id', $shop->id)
            ->set('qty', '12.5')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stocks', [
            'item_id' => $item->id,
            'shop_id' => $shop->id,
            'qty' => 12.5,
        ]);
    }

    public function test_stock_can_be_edited(): void
    {
        $user = User::factory()->create();
        $stock = Stock::factory()->create(['qty' => 5]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('edit', $stock->id)
            ->set('qty', '99')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'qty' => 99]);
    }

    public function test_stock_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $stock = Stock::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('delete', $stock->id);

        $this->assertDatabaseMissing('stocks', ['id' => $stock->id]);
    }

    public function test_duplicate_item_and_shop_combination_surfaces_friendly_validation_error(): void
    {
        $user = User::factory()->create();
        $item = Item::factory()->create();
        $shop = Shop::factory()->create();

        Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $shop->id]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('create')
            ->set('item_id', $item->id)
            ->set('shop_id', $shop->id)
            ->set('qty', '3')
            ->call('save')
            ->assertHasErrors(['shop_id' => 'unique']);

        $this->assertSame(1, Stock::query()->where('item_id', $item->id)->where('shop_id', $shop->id)->count());
    }

    public function test_editing_a_stock_record_does_not_trip_the_duplicate_check_against_itself(): void
    {
        $user = User::factory()->create();
        $stock = Stock::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('edit', $stock->id)
            ->set('qty', '42')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('stocks', ['id' => $stock->id, 'qty' => 42]);
    }
}
