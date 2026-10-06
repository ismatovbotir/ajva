<?php

namespace Tests\Feature;

use App\Jobs\ProcessReceiptIngestion;
use App\Livewire\Monitor;
use App\Models\Item;
use App\Models\ItemPrice;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\ReceiptPayment;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

class MonitorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(Shop $shop, Item $item, string $at, float $qty, float $total, array $attrs = []): Receipt
    {
        $receipt = Receipt::factory()->create(array_merge([
            'shop_id' => $shop->id, 'active' => true, 'sell' => true, 'total' => $total, 'created_at' => $at,
        ], $attrs));
        ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'qty' => $qty, 'total' => $total, 'storno' => false]);

        return $receipt;
    }

    public function test_guest_is_redirected_and_user_sees_the_monitor(): void
    {
        $this->get('/monitor')->assertRedirect('/login');

        $this->actingAs(User::factory()->create())->get('/monitor')
            ->assertOk()
            ->assertSeeLivewire(Monitor::class)
            ->assertSee('wire:poll.30s', false)
            ->assertSee(__('Waiting for the first sale today'));
    }

    public function test_hourly_payment_profit_and_ticker_numbers(): void
    {
        Carbon::setTestNow('2026-10-06 15:30:00');
        $user = User::factory()->create();
        $shop = Shop::factory()->create(['name' => 'Chilonzor']);
        $item = Item::factory()->create(['name' => 'Cola']);
        $cost = Price::factory()->create(['id' => config('inventory.cost_price_id'), 'name' => 'Cost', 'is_sell' => false]);
        ItemPrice::factory()->create(['item_id' => $item->id, 'price_id' => $cost->id, 'value' => 10]);

        $r1 = $this->sale($shop, $item, '2026-10-06 09:10:00', 2, 100);       // profit 80
        $r2 = $this->sale($shop, $item, '2026-10-06 09:40:00', 1, 50);        // profit 40
        $this->sale($shop, $item, '2026-10-06 11:00:00', 1, 30, ['sell' => false]); // refund
        $this->sale($shop, $item, '2026-10-05 09:00:00', 1, 40);               // yesterday
        ReceiptPayment::factory()->create(['receipt_id' => $r1->id, 'payment' => 'cash', 'value' => 100]);
        ReceiptPayment::factory()->create(['receipt_id' => $r2->id, 'payment' => 'card', 'value' => 50]);

        $c = Livewire::actingAs($user)->test(Monitor::class);

        $this->assertSame('150', $c->viewData('charts')['sum'][9]['today']);
        $this->assertEquals(150.0, $c->viewData('charts')['sum'][9]['total']);
        $this->assertEquals(40.0, $c->viewData('charts')['sum'][9]['y_total']);
        $this->assertEquals(120.0, $c->viewData('profit')['total']);
        $this->assertSame(15, $c->viewData('currentHour'));

        $pay = collect($c->viewData('payments'))->keyBy('name');
        $this->assertEqualsWithDelta(66.67, $pay['cash']['percent'], 0.01);

        $latest = $c->viewData('latest');
        $this->assertCount(3, $latest);
        $this->assertTrue($latest[0]['refund']);          // newest first (11:00 refund)
        $this->assertSame('11:00:00', $latest[0]['time']);
        $this->assertSame('card', $latest[1]['payment']);
        $this->assertSame('Chilonzor', $latest[1]['shop']);

        $c->assertSee('Cola')->assertSee('Chilonzor');
    }

    public function test_empty_day_renders_a_friendly_state(): void
    {
        Carbon::setTestNow('2026-10-06 08:00:00');
        $c = Livewire::actingAs(User::factory()->create())->test(Monitor::class);

        $this->assertSame(0, $c->viewData('totals')['count']);
        $this->assertSame([], $c->viewData('latest'));
        $c->assertSee(__('Waiting for the first sale today'));
    }

    public function test_data_is_cached_and_cleared_when_a_receipt_is_ingested(): void
    {
        Carbon::setTestNow('2026-10-06 15:30:00');
        $user = User::factory()->create();
        $shop = Shop::factory()->create();
        $item = Item::factory()->create();

        Livewire::actingAs($user)->test(Monitor::class);
        $this->assertTrue(Cache::has(Monitor::cacheKey()));

        ProcessReceiptIngestion::dispatchSync([
            'shop' => $shop->id,
            'pos' => 1,
            'number' => 'M-1',
            'openDate' => now()->format('d.m.y'),
            'openTime' => '10:00:00',
            'total' => 10,
            'positions' => [['item' => ['id' => $item->id], 'qty' => 1, 'totalSum' => 10]],
        ], 1);

        $this->assertFalse(Cache::has(Monitor::cacheKey()));
    }

    public function test_navigation_lists_the_monitor(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertOk()->assertSee(route('monitor'));
    }
}
