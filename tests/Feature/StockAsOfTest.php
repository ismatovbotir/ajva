<?php

namespace Tests\Feature;

use App\Livewire\Analytics\Index as AnalyticsIndex;
use App\Mcp\InvalidArguments;
use App\Mcp\Tools\StockLevels;
use App\Models\Item;
use App\Models\Shop;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reports with a date picker read stock "as of" that day: the latest snapshot
 * on or before it (Stock::applyAsOf), not simply the newest row.
 */
class StockAsOfTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-07 12:00:00');

        $this->shop = Shop::factory()->create(['name' => 'Shop One']);
        $this->item = Item::factory()->create(['name' => 'Item One']);

        foreach (['2026-10-01' => 100, '2026-10-05' => 60, '2026-10-07' => 40] as $date => $qty) {
            Stock::query()->create(['shop_id' => $this->shop->id, 'item_id' => $this->item->id, 'qty' => $qty, 'stock_date' => $date]);
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function analyticsRow(string $date): array
    {
        $component = Livewire::actingAs(User::factory()->create())->test(AnalyticsIndex::class)
            ->set('date', $date)
            ->call('generate')
            ->call('selectShop', $this->shop->id);

        return $component->viewData('rows')->firstWhere('item_id', $this->item->id);
    }

    public function test_analytics_uses_the_snapshot_on_or_before_the_selected_date(): void
    {
        $this->assertEquals(40, $this->analyticsRow('2026-10-07')['stock']);   // today: newest snapshot
        $this->assertEquals(60, $this->analyticsRow('2026-10-06')['stock']);   // carry-forward of the 5th
        $this->assertEquals(60, $this->analyticsRow('2026-10-05')['stock']);   // exact day
        $this->assertEquals(100, $this->analyticsRow('2026-10-03')['stock']);  // between snapshots
    }

    public function test_analytics_shows_which_snapshot_each_row_used_and_zero_before_the_first_one(): void
    {
        $this->assertSame('2026-10-05', substr((string) $this->analyticsRow('2026-10-06')['stock_date'], 0, 10));

        // Before any snapshot existed there is no stock row at all.
        $before = $this->analyticsRow('2026-09-30');
        $this->assertEquals(0, $before['stock']);
        $this->assertNull($before['stock_date']);
    }

    public function test_the_stock_date_is_rendered_in_the_report(): void
    {
        Livewire::actingAs(User::factory()->create())->test(AnalyticsIndex::class)
            ->set('date', '2026-10-06')
            ->call('generate')
            ->call('selectShop', $this->shop->id)
            ->assertSee('05.10.2026')
            ->assertSee(__('Stock date'));
    }

    public function test_the_mcp_stock_tool_takes_an_optional_as_of_date(): void
    {
        $tool = new StockLevels;

        $current = $tool->handle(['shop_id' => $this->shop->id]);
        $this->assertNull($current['as_of']);
        $this->assertEquals(40, $current['rows'][0]['qty']);

        $past = $tool->handle(['shop_id' => $this->shop->id, 'date' => '2026-10-03']);
        $this->assertSame('2026-10-03', $past['as_of']);
        $this->assertCount(1, $past['rows']);            // one row per item and shop, never history
        $this->assertEquals(100, $past['rows'][0]['qty']);
        $this->assertSame('2026-10-01', substr((string) $past['rows'][0]['stock_date'], 0, 10));

        $this->assertSame([], $tool->handle(['shop_id' => $this->shop->id, 'date' => '2026-09-30'])['rows']);

        $this->expectException(InvalidArguments::class);
        $tool->handle(['date' => '2026-13-45']);
    }
}
