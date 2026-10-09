<?php

namespace Tests\Feature;

use App\Jobs\ProcessReceiptIngestion;
use App\Livewire\SalesBoard;
use App\Models\Item;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\User;
use App\Support\ShopAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class SalesBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_board_compares_today_with_previous_day_and_ranks_top_items(): void
    {
        Carbon::setTestNow('2026-10-06 15:30:00');
        $user = User::factory()->create();
        $shop = Shop::factory()->create();
        $a = Item::factory()->create(['name' => 'Item A']);
        $b = Item::factory()->create(['name' => 'Item B']);

        $mk = fn (array $attrs) => Receipt::factory()->create(array_merge(['shop_id' => $shop->id, 'active' => true, 'sell' => true], $attrs));
        $line = fn (Receipt $r, Item $i, float $qty, float $total) => ReceiptItem::factory()->create(
            ['receipt_id' => $r->id, 'item_id' => $i->id, 'qty' => $qty, 'total' => $total, 'storno' => false]
        );

        $t1 = $mk(['total' => 100, 'created_at' => '2026-10-06 09:10:00']);
        $t2 = $mk(['total' => 50, 'created_at' => '2026-10-06 09:40:00']);
        $mk(['total' => 999, 'created_at' => '2026-10-06 10:00:00', 'sell' => false]);   // refund: excluded
        $mk(['total' => 999, 'created_at' => '2026-10-06 10:00:00', 'active' => false]); // cancelled: excluded
        $mk(['total' => 40, 'created_at' => '2026-10-05 09:30:00']);                     // yesterday, before cut-off
        $mk(['total' => 777, 'created_at' => '2026-10-05 20:00:00']);                    // yesterday, after cut-off

        $line($t1, $a, 3, 90);
        $line($t1, $b, 1, 10);
        $line($t2, $a, 2, 50);

        $data = Livewire::actingAs($user)->test(SalesBoard::class)->viewData('table');

        $this->assertCount(1, $data);
        $this->assertSame(2, $data[0]['count']);
        $this->assertEquals(150, $data[0]['sum']);
        $this->assertSame(1, $data[0]['y_count']);       // same-time cut-off
        $this->assertEquals(40, $data[0]['y_sum']);
        $this->assertEquals(275.0, $data[0]['sum_delta']); // (150-40)/40

        $component = Livewire::actingAs($user)->test(SalesBoard::class);
        $hour9 = $component->viewData('charts')['sum'][9];
        $this->assertSame('150', $hour9['today']);
        $this->assertSame('40', $hour9['yesterday']);
        $top = $component->viewData('topItems');
        $this->assertSame('Item A', $top['all'][0]['name']);
        $this->assertSame('5', $top['all'][0]['qty']);
        $this->assertSame('Item B', $top['all'][1]['name']);
        $this->assertCount(1, $top['shops']);
    }

    public function test_board_shows_the_whole_previous_day_next_to_the_same_time_figure(): void
    {
        Carbon::setTestNow('2026-10-06 15:30:00');
        $shop = Shop::factory()->create();
        $mk = fn (string $at, float $total, array $o = []) => Receipt::factory()->create(array_merge(
            ['shop_id' => $shop->id, 'active' => true, 'sell' => true, 'total' => $total, 'created_at' => $at], $o
        ));

        $mk('2026-10-06 10:00:00', 300);               // today
        $mk('2026-10-05 10:00:00', 100);               // yesterday, before the cut
        $mk('2026-10-05 20:00:00', 400);               // yesterday, after the cut: full day only
        $mk('2026-10-05 21:00:00', 999, ['active' => false]); // cancelled: never counted

        $totals = app(\App\Services\SalesMetrics::class)->board(now(), null, false)['totals'];

        $this->assertEquals(100.0, $totals['y_sum']);
        $this->assertEquals(500.0, $totals['yf_sum']);
        $this->assertSame(2, $totals['yf_count']);
        $this->assertEquals(250.0, $totals['yf_avg']);
    }

    public function test_ingesting_a_receipt_clears_the_boards_cache(): void
    {
        $shop = Shop::factory()->create();
        $item = Item::factory()->create();
        $keyFor = fn () => ShopAccess::salesKey('dashboard.sales-board', now()->toDateString(), null);
        $before = $keyFor();
        Cache::put($before, ['stale' => true], 50);

        ProcessReceiptIngestion::dispatchSync([
            'shop' => $shop->id,
            'pos' => 1,
            'number' => 'R-1',
            'openDate' => now()->format('d.m.y'),
            'openTime' => '10:00:00',
            'total' => 10,
            'positions' => [['item' => ['id' => $item->id], 'qty' => 1, 'totalSum' => 10]],
        ], 1);

        // The version is part of every key, so the stale entry is simply never read again.
        $this->assertNotSame($before, $keyFor());
    }

    public function test_dashboard_embeds_the_board_polling_every_two_minutes_without_a_refresh_button(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/')
            ->assertOk()
            ->assertSeeLivewire(SalesBoard::class)
            ->assertSee('wire:poll.120s', false)
            ->assertDontSee('wire:click="refresh"', false);
    }
}
