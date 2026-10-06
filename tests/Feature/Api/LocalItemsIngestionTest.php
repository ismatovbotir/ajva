<?php

namespace Tests\Feature\Api;

use App\Models\Barcode;
use App\Models\ItemPrice;
use App\Models\Price;
use App\Models\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalItemsIngestionTest extends TestCase
{
    use RefreshDatabase;

    protected function samplePayload(): array
    {
        return json_decode(
            file_get_contents(base_path('.claude/sample/request.json')),
            true
        );
    }

    public function test_request_from_a_public_ip_is_rejected(): void
    {
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->postJson('/api/items', [$this->minimalItem(1)]);

        $response->assertForbidden();
    }

    public function test_request_from_a_private_ip_is_allowed(): void
    {
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '192.168.1.50'])
            ->postJson('/api/items', [$this->minimalItem(1)]);

        $response->assertStatus(202);
    }

    public function test_request_from_loopback_is_allowed(): void
    {
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '127.0.0.1'])
            ->postJson('/api/items', [$this->minimalItem(1)]);

        $response->assertStatus(202);
    }

    public function test_full_sample_payload_is_ingested_correctly(): void
    {
        // Shops are no longer pre-seeded here on purpose: the sample payload
        // only references shops 1 and 2 through qty[]/order[], and the batch
        // must auto-create them (with the right name) from that alone.
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->postJson('/api/items', $this->samplePayload());

        $response->assertStatus(202);

        $this->assertDatabaseHas('shops', ['id' => 1, 'name' => 'Magazin 1']);
        $this->assertDatabaseHas('shops', ['id' => 2, 'name' => 'Magazin 2']);

        $this->assertDatabaseHas('groups', ['id' => 5, 'name' => 'Magizlar']);

        $this->assertDatabaseHas('items', [
            'id' => 123,
            'group_id' => 5,
            'mark' => '123',
            'name' => 'Ajwa keshu yongoq',
            'class_code' => '012345678978548748',
            'package_code' => '1234567',
        ]);

        $this->assertSame(2, Barcode::where('item_id', 123)->count());
        $this->assertDatabaseHas('barcodes', ['item_id' => 123, 'gtin' => '1231231231']);
        $this->assertDatabaseHas('barcodes', ['item_id' => 123, 'gtin' => '123123123']);

        // Both price entries in the sample share price id 1, so only one
        // price row/name (the last one processed) survives the dedupe.
        $this->assertSame(1, Price::count());
        // Price id 1 is the cost price, so the sync marks it as not a selling price.
        $this->assertDatabaseHas('prices', ['id' => 1, 'is_sell' => false]);

        $this->assertSame(1, ItemPrice::where('item_id', 123)->count());
        $this->assertDatabaseHas('item_prices', [
            'item_id' => 123,
            'price_id' => 1,
            'value' => '35000.00',
        ]);

        $this->assertDatabaseHas('stocks', ['item_id' => 123, 'shop_id' => 1, 'qty' => '125.000']);
        $this->assertDatabaseHas('stocks', ['item_id' => 123, 'shop_id' => 2, 'qty' => '300.000']);

        $this->assertDatabaseHas('item_order_rules', [
            'item_id' => 123, 'shop_id' => 1, 'min' => '123.000', 'max' => '1000.000',
        ]);
        $this->assertDatabaseHas('item_order_rules', [
            'item_id' => 123, 'shop_id' => 2, 'min' => '300.000', 'max' => '1500.000',
        ]);
    }

    public function test_malformed_item_is_rejected_with_validation_errors(): void
    {
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->postJson('/api/items', [
                [
                    // Missing required "id" and "name".
                    'mark' => '999',
                ],
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['items.0.id', 'items.0.name']);
    }

    public function test_validation_failure_returns_json_even_without_an_accept_header(): void
    {
        // Regression test: postJson() auto-sends "Accept: application/json",
        // which masked a real bug where a plain client (no Accept header,
        // as 1C's caller may well be) got redirected to the app root on
        // validation failure instead of receiving a JSON error. Use a raw
        // post() with a manually-encoded JSON body and no Accept header.
        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->call('POST', '/api/items', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
            ], json_encode([['mark' => '999']]));

        $response->assertStatus(422);
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertJsonValidationErrors(['items.0.id', 'items.0.name']);
    }

    public function test_shop_referenced_only_in_qty_and_order_is_created_automatically(): void
    {
        $this->assertDatabaseMissing('shops', ['id' => 999]);

        $payload = [[
            'id' => 456,
            'name' => 'Test item',
            'qty' => [
                ['shop' => ['id' => 999, 'name' => 'New shop'], 'value' => 20],
            ],
            'order' => [
                ['shop' => ['id' => 999, 'name' => 'New shop'], 'min' => 5, 'max' => 50],
            ],
        ]];

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->postJson('/api/items', $payload);

        $response->assertStatus(202);

        $this->assertDatabaseHas('items', ['id' => 456, 'name' => 'Test item']);
        $this->assertDatabaseHas('shops', ['id' => 999, 'name' => 'New shop']);
        $this->assertDatabaseHas('stocks', ['item_id' => 456, 'shop_id' => 999, 'qty' => '20.000']);
        $this->assertDatabaseHas('item_order_rules', [
            'item_id' => 456, 'shop_id' => 999, 'min' => '5.000', 'max' => '50.000',
        ]);
        $this->assertSame(1, Stock::where('item_id', 456)->count());
    }

    protected function minimalItem(int $id): array
    {
        return [
            'id' => $id,
            'name' => 'Minimal item',
        ];
    }
}
