<?php

namespace Tests\Feature\Api;

use App\Livewire\Settings\ReceiptsSync as ReceiptsSyncPage;
use App\Models\Item;
use App\Models\Pos;
use App\Models\Receipt;
use App\Models\Shop;
use App\Models\User;
use App\Support\ReceiptsSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** 1C history migration: local-only GET last receipt time, POST batches of up to 100 receipts, global cursor, per-receipt rejection. */
class ReceiptHistorySyncTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::factory()->create();
        $this->item = Item::factory()->create();
    }

    /** @param  array<string, mixed>  $o */
    private function receipt(string $number, string $date = '12.08.26', string $time = '10:00:00', array $o = []): array
    {
        return array_merge([
            'shop' => $this->shop->id,
            'pos' => 7,
            'session' => 5,
            'number' => $number,
            'openDate' => $date,
            'openTime' => $time,
            'closeDate' => '',
            'status' => 'success',
            'total' => 1000,
            'sum' => 1000,
            'positions' => [['item' => ['id' => $this->item->id], 'qty' => 1, 'storno' => 0, 'sum' => 1000, 'totalSum' => 1000]],
            'payments' => [['name' => 'cash', 'value' => 1000]],
        ], $o);
    }

    public function test_the_endpoints_are_local_network_only_and_need_no_token(): void
    {
        $this->getJson('/api/receipts/last')->assertOk();                                              // 127.0.0.1 in tests
        $this->postJson('/api/receipts/batch', [])->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->getJson('/api/receipts/last')->assertForbidden();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->postJson('/api/receipts/batch', [$this->receipt('1')])->assertForbidden();
        $this->assertSame(0, Receipt::count());
    }

    public function test_last_returns_null_at_the_start_and_the_cursor_in_1c_format_afterwards(): void
    {
        $this->getJson('/api/receipts/last')->assertOk()->assertJson(['last_receipt_at' => null, 'date' => null, 'time' => null, 'batch_size' => 100]);

        $this->postJson('/api/receipts/batch', [$this->receipt('1', '12.08.26', '10:00:00'), $this->receipt('2', '13.08.26', '18:30:05')])->assertOk();

        $this->getJson('/api/receipts/last')->assertOk()->assertJson(['last_receipt_at' => '2026-08-13 18:30:05', 'date' => '13.08.26', 'time' => '18:30:05']);
    }

    public function test_a_batch_stores_receipts_with_items_and_payments_and_moves_the_cursor_to_the_newest(): void
    {
        $response = $this->postJson('/api/receipts/batch', [
            $this->receipt('A', '01.01.20', '09:00:00'),
            $this->receipt('B', '03.01.20', '12:00:00'),
            $this->receipt('C', '02.01.20', '08:00:00'),   // not in time order: the cursor still takes the newest
        ])->assertOk()->assertJson(['accepted' => 3, 'rejected' => [], 'last_receipt_at' => '2020-01-03 12:00:00']);

        $this->assertSame(3, Receipt::count());
        $a = Receipt::where('number', 'A')->firstOrFail();
        $this->assertSame('2020-01-01 09:00:00', $a->created_at->format('Y-m-d H:i:s'), 'the receipt keeps its real time');
        $this->assertSame(1, $a->items()->count());
        $this->assertSame(1, $a->payments()->count());
        $this->assertSame(1, Pos::where('id', 7)->count());
        $this->assertSame(3, $response->json('accepted'));
    }

    public function test_a_receipt_with_an_unknown_item_is_rejected_alone_and_reported(): void
    {
        $bad = $this->receipt('BAD', '05.01.20', '10:00:00', ['positions' => [['item' => ['id' => 999999], 'qty' => 1, 'storno' => 0, 'sum' => 10, 'totalSum' => 10]]]);

        $response = $this->postJson('/api/receipts/batch', [$this->receipt('OK1', '04.01.20'), $bad, $this->receipt('OK2', '06.01.20')])->assertOk();

        $response->assertJson(['accepted' => 2]);
        $this->assertCount(1, $response->json('rejected'));
        $this->assertSame([1, 7, 5, 'BAD'], [$response->json('rejected.0.index'), $response->json('rejected.0.pos'), $response->json('rejected.0.session'), $response->json('rejected.0.number')]);
        $this->assertNotSame('', $response->json('rejected.0.reason'));
        $this->assertSame(0, Receipt::where('number', 'BAD')->count(), 'nothing of the rejected receipt is stored');
        $this->assertSame(2, Receipt::count());

        $state = ReceiptsSync::state();
        $this->assertSame(['accepted' => 2, 'rejected' => 1], array_intersect_key($state['last_batch'], ['accepted' => 1, 'rejected' => 1]));
        $this->assertSame('BAD', $state['rejected'][0]['number']);
    }

    public function test_a_resend_updates_and_the_same_number_in_another_shift_is_a_new_receipt(): void
    {
        $this->postJson('/api/receipts/batch', [$this->receipt('1', '01.02.20', '10:00:00', ['total' => 100, 'sum' => 100])])->assertOk();
        $this->postJson('/api/receipts/batch', [$this->receipt('1', '01.02.20', '10:00:00', ['total' => 250, 'sum' => 250])])->assertOk();   // same pos+session+number
        $this->postJson('/api/receipts/batch', [$this->receipt('1', '02.02.20', '10:00:00', ['session' => 6])])->assertOk();                    // number restarted in a new shift

        $this->assertSame(2, Receipt::count());
        $this->assertEquals(250, (float) Receipt::where('session', 5)->value('total'));
    }

    public function test_the_cursor_never_moves_back_by_itself_and_the_admin_can_set_it(): void
    {
        $this->postJson('/api/receipts/batch', [$this->receipt('1', '10.03.21', '10:00:00')])->assertOk();
        $this->postJson('/api/receipts/batch', [$this->receipt('2', '01.01.20', '10:00:00')])->assertOk();   // older receipt
        $this->assertSame('2021-03-10 10:00:00', ReceiptsSync::lastReceiptAt()->format('Y-m-d H:i:s'));

        // The admin sets it back to import the period again; a following batch moves it forward from there.
        $admin = User::factory()->create();
        Livewire::actingAs($admin)->test(ReceiptsSyncPage::class)
            ->assertSet('lastReceiptAt', '2021-03-10T10:00:00')
            ->set('lastReceiptAt', '2020-01-01T00:00:00')->call('save')->assertHasNoErrors();
        $this->assertSame('2020-01-01 00:00:00', ReceiptsSync::lastReceiptAt()->format('Y-m-d H:i:s'));

        $this->postJson('/api/receipts/batch', [$this->receipt('3', '05.01.20', '10:00:00')])->assertOk();
        $this->assertSame('2020-01-05 10:00:00', ReceiptsSync::lastReceiptAt()->format('Y-m-d H:i:s'));

        Livewire::actingAs($admin)->test(ReceiptsSyncPage::class)->call('clear');
        $this->assertNull(ReceiptsSync::lastReceiptAt());
        $this->getJson('/api/receipts/last')->assertJson(['last_receipt_at' => null]);
    }

    public function test_the_source_of_a_receipt_is_recorded_pos_for_tills_and_history_for_the_batch(): void
    {
        config(['services.pos.token' => 'test-pos-token']);
        $till = $this->receipt('TILL-1', '01.03.26', '10:00:00', ['pos' => 3]);

        $this->withToken('test-pos-token')->postJson('/api/receipts', $till)->assertStatus(202);
        $this->postJson('/api/receipts/batch', [$this->receipt('OLD-1', '01.03.20', '10:00:00')])->assertOk();

        $this->assertSame('pos', Receipt::where('number', 'TILL-1')->value('source'));
        $this->assertSame('history', Receipt::where('number', 'OLD-1')->value('source'));
        $this->assertSame('pos', Receipt::factory()->create()->fresh()->source, 'the default of every existing and factory receipt');

        $admin = User::factory()->create();
        $history = Receipt::where('number', 'OLD-1')->firstOrFail();
        $this->actingAs($admin)->get(route('receipts.show', $history))->assertOk()->assertSee(__('Source: 1C history'));
        $this->actingAs($admin)->get(route('receipts.show', Receipt::where('number', 'TILL-1')->firstOrFail()))->assertOk()->assertSee(__('Source: till'));
    }

    public function test_a_refund_receipt_keeps_its_sell_flag_and_the_client(): void
    {
        $this->postJson('/api/receipts/batch', [
            $this->receipt('REF-1', '01.04.20', '10:00:00', ['sell' => false, 'client' => 'Ali']),
            $this->receipt('SALE-1', '01.04.20', '11:00:00', ['sell' => true]),
            $this->receipt('SALE-2', '01.04.20', '12:00:00'),
        ])->assertOk()->assertJson(['accepted' => 3]);

        $this->assertFalse((bool) Receipt::where('number', 'REF-1')->value('sell'));
        $this->assertSame('Ali', Receipt::where('number', 'REF-1')->value('client'));
        $this->assertTrue((bool) Receipt::where('number', 'SALE-1')->value('sell'));
        $this->assertTrue((bool) Receipt::where('number', 'SALE-2')->value('sell'), 'omitted = a sale');
    }

    public function test_the_size_limit_and_the_body_shape_are_enforced(): void
    {
        $this->postJson('/api/receipts/batch', array_fill(0, 101, $this->receipt('X')))->assertStatus(422);
        $this->postJson('/api/receipts/batch', ['not' => 'a list'])->assertStatus(422);
        $this->assertSame(0, Receipt::count());

        $hundred = [];
        for ($i = 1; $i <= 100; $i++) {
            $hundred[] = $this->receipt('N'.$i, '01.01.20', '10:00:00');
        }
        $this->postJson('/api/receipts/batch', $hundred)->assertOk()->assertJson(['accepted' => 100]);
    }

    public function test_the_sales_caches_are_invalidated_once_per_batch(): void
    {
        $before = (int) Cache::get('sales.version', 0);
        $this->postJson('/api/receipts/batch', [$this->receipt('1'), $this->receipt('2'), $this->receipt('3')])->assertOk();
        $after = (int) Cache::get('sales.version', 0);

        $this->assertSame($before + 1, $after);
    }

    public function test_the_bad_date_of_one_receipt_does_not_break_the_batch(): void
    {
        $response = $this->postJson('/api/receipts/batch', [$this->receipt('1', 'not-a-date'), $this->receipt('2', '01.01.20')])->assertOk();

        $response->assertJson(['accepted' => 1]);
        $this->assertCount(1, $response->json('rejected'));
    }

    public function test_the_settings_page_is_admin_only_and_shows_addresses_and_rejected_receipts(): void
    {
        $this->postJson('/api/receipts/batch', [$this->receipt('BAD', 'zzz')])->assertOk();

        $this->actingAs(User::factory()->operator()->create())->get(route('settings.receipts-sync'))->assertForbidden();
        $this->actingAs(User::factory()->create())->get(route('settings.receipts-sync'))
            ->assertOk()->assertSee('api/receipts/last')->assertSee('api/receipts/batch')->assertSee('BAD');
    }
}
