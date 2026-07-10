<?php

namespace Tests\Feature;

use App\Livewire\Stocks\Index;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StocksCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_stocks_list_renders_with_no_mutation_actions(): void
    {
        $user = User::factory()->create();
        $stock = Stock::factory()->create();

        $response = $this->actingAs($user)
            ->get('/stocks')
            ->assertOk()
            ->assertSeeLivewire(Index::class)
            ->assertSee($stock->item->name)
            ->assertSee($stock->shop->name);

        $response->assertDontSeeText(__('New stock'));
        $response->assertDontSeeText(__('Edit'));
        $response->assertDontSeeText(__('Delete'));
    }

    public function test_guest_cannot_access_stocks(): void
    {
        $this->get('/stocks')->assertRedirect('/login');
    }
}
