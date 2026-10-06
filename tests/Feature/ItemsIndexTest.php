<?php

namespace Tests\Feature;

use App\Livewire\Items\Index;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ItemsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_items_are_paginated_and_search_resets_to_the_first_page(): void
    {
        foreach (range(1, 60) as $i) {
            Item::factory()->create(['name' => sprintf('Item %03d', $i)]);
        }

        $component = Livewire::actingAs(User::factory()->create())->test(Index::class);

        $this->assertCount(50, $component->viewData('items')->items());
        $this->assertSame(60, $component->viewData('items')->total());

        $component->call('setPage', 2);
        $this->assertCount(10, $component->viewData('items')->items());

        $component->set('search', 'Item 05');
        $this->assertSame(1, $component->viewData('items')->currentPage());
        $this->assertSame(10, $component->viewData('items')->total()); // Item 050..059
    }
}
