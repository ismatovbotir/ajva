<?php

namespace Tests\Feature\Api;

use App\Models\Item;
use App\Models\Pos;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\ReceiptPayment;
use App\Models\Shop;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceiptIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'shop' => Shop::factory()->create()->id,
            'pos' => 1,
            'barcode' => '12345',
            'card' => '1234567890',
            'openDate' => '12.08.26',
            'openTime' => '17:52:46',
            'closeDate' => '',
            'number' => 'A-1024',
            'user' => [
                'id' => 1,
                'name' => 'Kassir nomi',
                'text' => 'Kassir nomi',
            ],
            'payments' => [
                ['name' => 'cash', 'value' => 100000],
                ['name' => 'card', 'value' => 30000],
            ],
            'positions' => [
                [
                    'item' => ['id' => null, 'art' => 'A123', 'name' => 'Test item', 'class_code' => 'ИКПУ 12345', 'package_code' => 'Certifi'],
                    'labels' => [],
                    'barcode' => '46057921',
                    'qty' => 2,
                    'storno' => 0,
                    'sum' => 130000,
                    'sumR' => 0,
                    'sumWD' => 130000,
                    'sumWT' => 34.23,
                    'totalSum' => 130000,
                ],
            ],
            'qtyBuys' => 2,
            'qtyPositions' => 1,
            'session' => 1,
            'type' => 1,
            'status' => 'success',
            'sum' => 135000,
            'sumWithDiscs' => 130000,
            'total' => 130000,
            'aos' => [],
            'fiscal' => '',
        ], $overrides);
    }

    protected function position(Item $item): array
    {
        return [
            'item' => ['id' => $item->id],
            'labels' => [],
            'barcode' => '1',
            'qty' => 1,
            'storno' => 0,
            'sum' => 1000,
            'sumWD' => 1000,
            'totalSum' => 1000,
        ];
    }

    public function test_request_without_valid_token_is_rejected(): void
    {
        $this->postJson('/api/receipts', $this->payload())
            ->assertStatus(401)
            ->assertJson(['message' => 'Invalid or missing API token.']);

        $this->withToken('wrong')->postJson('/api/receipts', $this->payload())->assertStatus(401);
    }

    public function test_missing_pos_is_created_with_default_name(): void
    {
        $shop = Shop::factory()->create();
        $item = Item::factory()->create();

        $this->withToken('test-pos-token')->postJson('/api/receipts', $this->payload([
            'shop' => $shop->id,
            'pos' => 7,
            'positions' => [$this->position($item)],
        ]))->assertStatus(202);

        $this->assertDatabaseHas('pos', ['id' => 7, 'name' => 'kassa 7', 'shop_id' => $shop->id]);
        $this->assertDatabaseHas('receipts', ['pos_id' => 7, 'shop_id' => $shop->id]);
    }

    public function test_existing_pos_is_reused_and_not_renamed(): void
    {
        $pos = Pos::factory()->create(['name' => 'Main till']);
        $item = Item::factory()->create();

        $this->withToken('test-pos-token')->postJson('/api/receipts', $this->payload([
            'pos' => $pos->id,
            'positions' => [$this->position($item)],
        ]))->assertStatus(202);

        $this->assertSame(1, Pos::count());
        $this->assertSame('Main till', $pos->fresh()->name);
    }

    public function test_unknown_shop_id_is_rejected(): void
    {
        $this->withToken('test-pos-token')->postJson('/api/receipts', $this->payload(['shop' => 999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shop']);
    }

    public function test_pos_is_required(): void
    {
        $payload = $this->payload();
        unset($payload['pos']);

        $this->withToken('test-pos-token')->postJson('/api/receipts', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['pos']);
    }

    public function test_ingests_a_receipt_with_positions_and_payments(): void
    {
        $shop = Shop::factory()->create();
        $pos = Pos::factory()->for($shop)->create();
        $item = Item::factory()->create();

        $payload = $this->payload([
            'positions' => [
                [
                    'item' => ['id' => $item->id, 'art' => 'A123', 'name' => 'Test item', 'class_code' => 'ИКПУ 12345', 'package_code' => 'Certifi'],
                    'labels' => [],
                    'barcode' => '46057921',
                    'qty' => 2,
                    'storno' => 0,
                    'sum' => 135000,
                    'sumR' => 0,
                    'sumWD' => 130000,
                    'sumWT' => 34.23,
                    'totalSum' => 130000,
                ],
            ],
        ]);

        $response = $this->withToken('test-pos-token')->postJson('/api/receipts', $payload);

        $response->assertStatus(202);

        $this->assertDatabaseHas('receipts', [
            'pos_id' => $pos->id,
            'shop_id' => $shop->id,
            'number' => 'A-1024',
            'cashier' => 'Kassir nomi',
            'total' => '130000.00',
            'discount' => '5000.00',
            'gross_total' => '135000.00',
            'barcode' => '12345',
            'card' => '1234567890',
            'status' => 'success',
            'active' => 1,
            'sell' => 1,
            'pos_user_id' => 1,
            'pos_user_name' => 'Kassir nomi',
        ]);

        $receipt = Receipt::query()->where('pos_id', $pos->id)->where('number', 'A-1024')->firstOrFail();

        // openDate "12.08.26" + openTime "17:52:46"; closeDate is empty in
        // this payload so updated_at falls back to the same instant.
        $this->assertSame('2026-08-12 17:52:46', $receipt->created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-12 17:52:46', $receipt->updated_at->format('Y-m-d H:i:s'));

        $this->assertSame(1, ReceiptItem::where('receipt_id', $receipt->id)->count());
        $this->assertDatabaseHas('receipt_items', [
            'receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'qty' => '2.000',
            'price' => '65000.00',
            'total' => '130000.00',
            'discount' => '5000.00',
            'art' => 'A123',
            'name' => 'Test item',
            'line_barcode' => '46057921',
            'storno' => 0,
            'receipt_active' => 1,
            'receipt_sell' => 1,
        ]);

        $this->assertSame(2, ReceiptPayment::where('receipt_id', $receipt->id)->count());
        $this->assertDatabaseHas('receipt_payments', [
            'receipt_id' => $receipt->id,
            'payment' => 'cash',
            'value' => '100000.00',
        ]);
        $this->assertDatabaseHas('receipt_payments', [
            'receipt_id' => $receipt->id,
            'payment' => 'card',
            'value' => '30000.00',
        ]);
    }

    public function test_close_date_present_is_used_for_updated_at(): void
    {
        $shop = Shop::factory()->create();
        $pos = Pos::factory()->for($shop)->create();
        $item = Item::factory()->create();

        $payload = $this->payload([
            'closeDate' => '12.08.26',
            'positions' => [
                [
                    'item' => ['id' => $item->id],
                    'labels' => [],
                    'barcode' => '46057921',
                    'qty' => 2,
                    'storno' => 0,
                    'sum' => 130000,
                    'sumWD' => 130000,
                    'totalSum' => 130000,
                ],
            ],
        ]);

        $this->withToken('test-pos-token')->postJson('/api/receipts', $payload)->assertStatus(202);

        $receipt = Receipt::query()->where('pos_id', $pos->id)->where('number', 'A-1024')->firstOrFail();

        // No closeTime field exists in the payload, so openTime's
        // time-of-day is used as a stand-in alongside closeDate's date.
        $this->assertSame('2026-08-12 17:52:46', $receipt->updated_at->format('Y-m-d H:i:s'));
    }

    public function test_a_storno_line_is_marked_inactive_without_affecting_other_lines(): void
    {
        $shop = Shop::factory()->create();
        $pos = Pos::factory()->for($shop)->create();
        $itemOne = Item::factory()->create();
        $itemTwo = Item::factory()->create();

        $payload = $this->payload([
            'positions' => [
                [
                    'item' => ['id' => $itemOne->id],
                    'labels' => [],
                    'barcode' => '1',
                    'qty' => 1,
                    'storno' => 1,
                    'sum' => 1000,
                    'sumWD' => 1000,
                    'totalSum' => 1000,
                ],
                [
                    'item' => ['id' => $itemTwo->id],
                    'labels' => [],
                    'barcode' => '2',
                    'qty' => 1,
                    'storno' => 0,
                    'sum' => 2000,
                    'sumWD' => 2000,
                    'totalSum' => 2000,
                ],
            ],
        ]);

        $this->withToken('test-pos-token')->postJson('/api/receipts', $payload)->assertStatus(202);

        $receipt = Receipt::query()->where('pos_id', $pos->id)->where('number', 'A-1024')->firstOrFail();

        $this->assertDatabaseHas('receipt_items', [
            'receipt_id' => $receipt->id,
            'item_id' => $itemOne->id,
            'storno' => 1,
            'active' => 0,
        ]);
        $this->assertDatabaseHas('receipt_items', [
            'receipt_id' => $receipt->id,
            'item_id' => $itemTwo->id,
            'storno' => 0,
            'active' => 1,
        ]);
    }

    public function test_resubmitting_the_same_pos_and_number_updates_instead_of_duplicating(): void
    {
        $shop = Shop::factory()->create();
        $pos = Pos::factory()->for($shop)->create();
        $itemOne = Item::factory()->create();
        $itemTwo = Item::factory()->create();

        $firstPayload = $this->payload([
            'total' => 130000,
            'sum' => 130000,
            'positions' => [
                [
                    'item' => ['id' => $itemOne->id],
                    'labels' => [],
                    'barcode' => '1',
                    'qty' => 2,
                    'storno' => 0,
                    'sum' => 130000,
                    'sumWD' => 130000,
                    'totalSum' => 130000,
                ],
            ],
        ]);

        $this->withToken('test-pos-token')->postJson('/api/receipts', $firstPayload)->assertStatus(202);

        $this->assertSame(1, Receipt::count());
        $receiptId = Receipt::query()->where('pos_id', $pos->id)->where('number', 'A-1024')->value('id');
        $this->assertSame(1, ReceiptItem::where('receipt_id', $receiptId)->count());

        // Retry with the same number, but different line items/total — should
        // update the same receipt and fully replace its items, not append.
        $secondPayload = $this->payload([
            'total' => 90000,
            'sum' => 90000,
            'positions' => [
                [
                    'item' => ['id' => $itemTwo->id],
                    'labels' => [],
                    'barcode' => '2',
                    'qty' => 3,
                    'storno' => 0,
                    'sum' => 90000,
                    'sumWD' => 90000,
                    'totalSum' => 90000,
                ],
            ],
        ]);

        $this->withToken('test-pos-token')->postJson('/api/receipts', $secondPayload)->assertStatus(202);

        $this->assertSame(1, Receipt::count());

        $receipt = Receipt::query()->where('pos_id', $pos->id)->where('number', 'A-1024')->firstOrFail();
        $this->assertSame($receiptId, $receipt->id);
        $this->assertSame('90000.00', $receipt->total);

        $this->assertSame(1, ReceiptItem::where('receipt_id', $receiptId)->count());
        $this->assertDatabaseHas('receipt_items', [
            'receipt_id' => $receiptId,
            'item_id' => $itemTwo->id,
            'qty' => '3.000',
        ]);
        $this->assertDatabaseMissing('receipt_items', [
            'receipt_id' => $receiptId,
            'item_id' => $itemOne->id,
        ]);
    }

    public function test_a_different_pos_can_reuse_the_same_receipt_number(): void
    {
        $posOne = Pos::factory()->create();
        $posTwo = Pos::factory()->create();
        $item = Item::factory()->create();

        $payload = $this->payload([
            'positions' => [
                [
                    'item' => ['id' => $item->id],
                    'labels' => [],
                    'barcode' => '1',
                    'qty' => 1,
                    'storno' => 0,
                    'sum' => 1000,
                    'sumWD' => 1000,
                    'totalSum' => 1000,
                ],
            ],
        ]);

        $this->withToken('test-pos-token')->postJson('/api/receipts', array_merge($payload, ['pos' => $posOne->id]))->assertStatus(202);
        $this->withToken('test-pos-token')->postJson('/api/receipts', array_merge($payload, ['pos' => $posTwo->id]))->assertStatus(202);

        $this->assertSame(2, Receipt::where('number', 'A-1024')->count());
    }

    public function test_validation_failure_returns_json(): void
    {
        $pos = Pos::factory()->create();

        $response = $this->withToken('test-pos-token')->postJson('/api/receipts', ['number' => 'A-1']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['total', 'positions', 'openDate', 'openTime']);
    }

    public function test_validation_failure_returns_json_even_without_an_accept_header(): void
    {
        // Regression test: postJson() auto-sends "Accept: application/json",
        // which masked a real bug where a plain client (no Accept header, as
        // a POS terminal may well be) got redirected to the app root on
        // validation failure instead of receiving a JSON error. Use a raw
        // post() with a manually-encoded JSON body and no Accept header.
        $pos = Pos::factory()->create();

        // Note: withHeader() only affects Laravel's postJson()/getJson()
        // helpers, not the raw call() below — the Authorization header has
        // to be passed directly in the $server array here.
        $response = $this->call('POST', '/api/receipts', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer test-pos-token',
        ], json_encode(['number' => 'A-1']));

        $response->assertStatus(422);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonValidationErrors(['total', 'positions']);
    }

    public function test_ingestion_does_not_touch_stocks(): void
    {
        $shop = Shop::factory()->create();
        $pos = Pos::factory()->for($shop)->create();
        $item = Item::factory()->create();

        Stock::query()->create(['item_id' => $item->id, 'shop_id' => $shop->id, 'qty' => 50]);

        $payload = $this->payload([
            'positions' => [
                [
                    'item' => ['id' => $item->id],
                    'labels' => [],
                    'barcode' => '1',
                    'qty' => 2,
                    'storno' => 0,
                    'sum' => 130000,
                    'sumWD' => 130000,
                    'totalSum' => 130000,
                ],
            ],
        ]);

        $this->withToken('test-pos-token')->postJson('/api/receipts', $payload)
            ->assertStatus(202);

        $this->assertDatabaseHas('stocks', ['item_id' => $item->id, 'shop_id' => $shop->id, 'qty' => '50.000']);
    }
}
