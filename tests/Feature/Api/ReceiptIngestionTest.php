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
            'number' => 'A-1024',
            'client' => 'Ism Familiya',
            'cashier' => 'Kassir nomi',
            'total' => 130000,
            'discount' => 5000,
            'active' => true,
            'sell' => true,
            'items' => [
                ['item_id' => null, 'qty' => 2, 'price' => 65000, 'discount' => 0, 'total' => 130000],
            ],
            'payments' => [
                ['payment' => 'cash', 'value' => 100000],
                ['payment' => 'card', 'value' => 30000],
            ],
        ], $overrides);
    }

    public function test_request_with_no_token_is_rejected(): void
    {
        $response = $this->postJson('/api/receipts', $this->payload());

        $response->assertStatus(401);
        $response->assertJson(['message' => 'Invalid or missing API token.']);
    }

    public function test_request_with_invalid_token_is_rejected(): void
    {
        Pos::factory()->create();

        $response = $this
            ->withHeader('Authorization', 'Bearer not-a-real-token')
            ->postJson('/api/receipts', $this->payload());

        $response->assertStatus(401);
    }

    public function test_valid_token_ingests_a_receipt_with_items_and_payments(): void
    {
        $shop = Shop::factory()->create();
        $pos = Pos::factory()->for($shop)->create();
        $token = $pos->issueApiToken();
        $item = Item::factory()->create();

        $payload = $this->payload([
            'items' => [
                ['item_id' => $item->id, 'qty' => 2, 'price' => 65000, 'discount' => 0, 'total' => 130000],
            ],
        ]);

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/receipts', $payload);

        $response->assertStatus(202);

        $this->assertDatabaseHas('receipts', [
            'pos_id' => $pos->id,
            'shop_id' => $shop->id,
            'number' => 'A-1024',
            'client' => 'Ism Familiya',
            'cashier' => 'Kassir nomi',
            'total' => '130000.00',
            'discount' => '5000.00',
            'active' => 1,
            'sell' => 1,
        ]);

        $receipt = Receipt::query()->where('pos_id', $pos->id)->where('number', 'A-1024')->firstOrFail();

        $this->assertSame(1, ReceiptItem::where('receipt_id', $receipt->id)->count());
        $this->assertDatabaseHas('receipt_items', [
            'receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'qty' => '2.000',
            'price' => '65000.00',
            'total' => '130000.00',
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

    public function test_resubmitting_the_same_pos_and_number_updates_instead_of_duplicating(): void
    {
        $shop = Shop::factory()->create();
        $pos = Pos::factory()->for($shop)->create();
        $token = $pos->issueApiToken();
        $itemOne = Item::factory()->create();
        $itemTwo = Item::factory()->create();

        $firstPayload = $this->payload([
            'total' => 130000,
            'items' => [
                ['item_id' => $itemOne->id, 'qty' => 2, 'price' => 65000, 'discount' => 0, 'total' => 130000],
            ],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/receipts', $firstPayload)->assertStatus(202);

        $this->assertSame(1, Receipt::count());
        $receiptId = Receipt::query()->where('pos_id', $pos->id)->where('number', 'A-1024')->value('id');
        $this->assertSame(1, ReceiptItem::where('receipt_id', $receiptId)->count());

        // Retry with the same number, but different line items/total — should
        // update the same receipt and fully replace its items, not append.
        $secondPayload = $this->payload([
            'total' => 90000,
            'items' => [
                ['item_id' => $itemTwo->id, 'qty' => 3, 'price' => 30000, 'discount' => 0, 'total' => 90000],
            ],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$token)->postJson('/api/receipts', $secondPayload)->assertStatus(202);

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
        $tokenOne = $posOne->issueApiToken();
        $tokenTwo = $posTwo->issueApiToken();
        $item = Item::factory()->create();

        $payload = $this->payload([
            'items' => [
                ['item_id' => $item->id, 'qty' => 1, 'price' => 1000, 'discount' => 0, 'total' => 1000],
            ],
        ]);

        $this->withHeader('Authorization', 'Bearer '.$tokenOne)->postJson('/api/receipts', $payload)->assertStatus(202);
        $this->withHeader('Authorization', 'Bearer '.$tokenTwo)->postJson('/api/receipts', $payload)->assertStatus(202);

        $this->assertSame(2, Receipt::where('number', 'A-1024')->count());
    }

    public function test_validation_failure_returns_json(): void
    {
        $pos = Pos::factory()->create();
        $token = $pos->issueApiToken();

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/receipts', ['number' => 'A-1']);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['total', 'items']);
    }

    public function test_validation_failure_returns_json_even_without_an_accept_header(): void
    {
        // Regression test: postJson() auto-sends "Accept: application/json",
        // which masked a real bug where a plain client (no Accept header, as
        // a POS terminal may well be) got redirected to the app root on
        // validation failure instead of receiving a JSON error. Use a raw
        // post() with a manually-encoded JSON body and no Accept header.
        $pos = Pos::factory()->create();
        $token = $pos->issueApiToken();

        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->call('POST', '/api/receipts', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
            ], json_encode(['number' => 'A-1']));

        $response->assertStatus(422);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonValidationErrors(['total', 'items']);
    }

    public function test_ingestion_does_not_touch_stocks(): void
    {
        $shop = Shop::factory()->create();
        $pos = Pos::factory()->for($shop)->create();
        $token = $pos->issueApiToken();
        $item = Item::factory()->create();

        Stock::query()->create(['item_id' => $item->id, 'shop_id' => $shop->id, 'qty' => 50]);

        $payload = $this->payload([
            'items' => [
                ['item_id' => $item->id, 'qty' => 2, 'price' => 65000, 'discount' => 0, 'total' => 130000],
            ],
        ]);

        $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/receipts', $payload)
            ->assertStatus(202);

        $this->assertDatabaseHas('stocks', ['item_id' => $item->id, 'shop_id' => $shop->id, 'qty' => '50.000']);
    }
}
