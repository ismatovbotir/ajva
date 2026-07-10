<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Models\Group;
use App\Models\Item;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class DashboardChartsTest extends TestCase
{
    use RefreshDatabase;

    private function seedKnownStock(): array
    {
        $shopA = Shop::factory()->create(['name' => 'Shop Alpha']);
        $shopB = Shop::factory()->create(['name' => 'Shop Beta']);

        $groupOne = Group::factory()->create(['name' => 'Group One']);
        $groupTwo = Group::factory()->create(['name' => 'Group Two']);

        $itemOne = Item::factory()->create(['group_id' => $groupOne->id]);
        $itemTwo = Item::factory()->create(['group_id' => $groupTwo->id]);
        $itemNoGroup = Item::factory()->create(['group_id' => null]);

        // Shop Alpha: 17 + 13 + 9 = 39
        Stock::factory()->create(['shop_id' => $shopA->id, 'item_id' => $itemOne->id, 'qty' => 17]);
        Stock::factory()->create(['shop_id' => $shopA->id, 'item_id' => $itemTwo->id, 'qty' => 13]);
        Stock::factory()->create(['shop_id' => $shopA->id, 'item_id' => $itemNoGroup->id, 'qty' => 9]);

        // Shop Beta: 8 + 6 + 4 = 18
        Stock::factory()->create(['shop_id' => $shopB->id, 'item_id' => $itemOne->id, 'qty' => 8]);
        Stock::factory()->create(['shop_id' => $shopB->id, 'item_id' => $itemTwo->id, 'qty' => 6]);
        Stock::factory()->create(['shop_id' => $shopB->id, 'item_id' => $itemNoGroup->id, 'qty' => 4]);

        return compact('shopA', 'shopB', 'groupOne', 'groupTwo', 'itemOne', 'itemTwo', 'itemNoGroup');
    }

    public function test_dashboard_aggregates_stock_by_shop_correctly(): void
    {
        $user = User::factory()->create();
        $this->seedKnownStock();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('shopRows', function (array $rows) {
                // Sorted descending by total: Shop Alpha (39) before Shop Beta (18).
                $this->assertSame('Shop Alpha', $rows[0]['name']);
                $this->assertEqualsWithDelta(39.0, $rows[0]['total'], 0.001);
                $this->assertSame('39', $rows[0]['label']);

                $this->assertSame('Shop Beta', $rows[1]['name']);
                $this->assertEqualsWithDelta(18.0, $rows[1]['total'], 0.001);
                $this->assertSame('18', $rows[1]['label']);

                return true;
            });
    }

    public function test_dashboard_aggregates_stock_by_group_and_rolls_up_ungrouped_items(): void
    {
        $user = User::factory()->create();
        $this->seedKnownStock();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('groupRows', function (array $rows) {
                // Group One: 17 + 8 = 25; Group Two: 13 + 6 = 19; No group: 9 + 4 = 13.
                $byName = collect($rows)->keyBy('name');

                $this->assertEqualsWithDelta(25.0, $byName['Group One']['total'], 0.001);
                $this->assertEqualsWithDelta(19.0, $byName['Group Two']['total'], 0.001);
                $this->assertEqualsWithDelta(13.0, $byName[__('No group')]['total'], 0.001);

                // Sorted descending by total.
                $this->assertSame('Group One', $rows[0]['name']);

                return true;
            });
    }

    public function test_dashboard_aggregates_stock_by_group_and_shop_combined(): void
    {
        $user = User::factory()->create();
        $this->seedKnownStock();

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('groupShop', function (array $groupShop) {
                $rows = collect($groupShop['rows'])->keyBy('name');

                $groupOneSegments = collect($rows['Group One']['segments'])->keyBy('name');
                $this->assertEqualsWithDelta(17.0, $groupOneSegments['Shop Alpha']['value'], 0.001);
                $this->assertEqualsWithDelta(8.0, $groupOneSegments['Shop Beta']['value'], 0.001);

                $noGroupSegments = collect($rows[__('No group')]['segments'])->keyBy('name');
                $this->assertEqualsWithDelta(9.0, $noGroupSegments['Shop Alpha']['value'], 0.001);
                $this->assertEqualsWithDelta(4.0, $noGroupSegments['Shop Beta']['value'], 0.001);

                // Only 2 shops exist, well under the 7-color cap, so no "Other" fold-in.
                $this->assertCount(2, $groupShop['legend']);
                $this->assertSame('Shop Alpha', $groupShop['legend'][0]['name']);
                $this->assertSame('Shop Beta', $groupShop['legend'][1]['name']);

                return true;
            });
    }

    public function test_dashboard_donut_folds_shops_beyond_the_top_five(): void
    {
        $user = User::factory()->create();

        $shops = Shop::factory()->count(7)->sequence(
            ['name' => 'Shop 1'],
            ['name' => 'Shop 2'],
            ['name' => 'Shop 3'],
            ['name' => 'Shop 4'],
            ['name' => 'Shop 5'],
            ['name' => 'Shop 6'],
            ['name' => 'Shop 7'],
        )->create();

        $item = Item::factory()->create(['group_id' => null]);

        foreach ($shops as $i => $shop) {
            // Descending quantities: 70, 60, 50, 40, 30, 20, 10.
            Stock::factory()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => 70 - $i * 10]);
        }

        Livewire::actingAs($user)
            ->test(Dashboard::class)
            ->assertViewHas('donut', function (array $donut) {
                // Top 5 (70,60,50,40,30) + Other (20+10=30) = 6 segments total.
                $this->assertCount(6, $donut['segments']);
                $this->assertSame(__('Other'), $donut['segments'][5]['name']);
                $this->assertEqualsWithDelta(30.0, $donut['segments'][5]['value'], 0.001);

                return true;
            });
    }

    public function test_dashboard_renders_end_to_end_for_a_logged_in_user(): void
    {
        $user = User::factory()->create();
        $this->seedKnownStock();

        $this->actingAs($user)
            ->get('/')
            ->assertOk()
            ->assertSeeLivewire(Dashboard::class)
            ->assertSee(__('Stock by shop'))
            ->assertSee(__('Stock by group'))
            ->assertSee(__('Stock by group and shop'))
            ->assertSee('Shop Alpha')
            ->assertSee('Shop Beta')
            ->assertSee('Group One')
            ->assertSee('Group Two')
            ->assertSee(__('No group'));
    }

    public function test_guest_cannot_access_dashboard(): void
    {
        $this->get('/')->assertRedirect('/login');
    }
}
