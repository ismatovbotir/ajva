<?php

namespace Tests\Feature;

use App\Livewire\Shops\Index;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopsCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_shops_list_renders_with_no_mutation_actions(): void
    {
        $user = User::factory()->create();
        Shop::factory()->create(['name' => 'Main shop']);

        $response = $this->actingAs($user)
            ->get('/shops')
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSee('Main shop');

        $response->assertDontSeeText(__('New shop'));
        $response->assertDontSeeText(__('Edit'));
        $response->assertDontSeeText(__('Delete'));
    }

    public function test_guest_cannot_access_shops(): void
    {
        $this->get('/shops')->assertRedirect('/login');
    }
}
