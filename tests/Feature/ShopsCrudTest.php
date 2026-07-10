<?php

namespace Tests\Feature;

use App\Livewire\Shops\Index;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ShopsCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_shops_list_renders_for_authenticated_user(): void
    {
        $user = User::factory()->create();
        Shop::factory()->create(['name' => 'Main shop']);

        $this->actingAs($user)
            ->get('/shops')
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSee('Main shop');
    }

    public function test_guest_cannot_access_shops(): void
    {
        $this->get('/shops')->assertRedirect('/login');
    }

    public function test_shop_can_be_created(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('create')
            ->set('name', 'New shop')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('shops', ['name' => 'New shop']);
    }

    public function test_shop_can_be_edited(): void
    {
        $user = User::factory()->create();
        $shop = Shop::factory()->create(['name' => 'Old name']);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('edit', $shop->id)
            ->set('name', 'Updated name')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('shops', ['id' => $shop->id, 'name' => 'Updated name']);
    }

    public function test_shop_requires_a_name(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('create')
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);
    }

    public function test_shop_can_be_deleted(): void
    {
        $user = User::factory()->create();
        $shop = Shop::factory()->create();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('delete', $shop->id);

        $this->assertDatabaseMissing('shops', ['id' => $shop->id]);
    }
}
