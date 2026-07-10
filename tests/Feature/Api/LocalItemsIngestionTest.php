<?php

namespace Tests\Feature\Api;

use App\Models\Barcode;
use App\Models\ItemPrice;
use App\Models\Price;
use App\Models\Shop;
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
        Shop::insert([
            ['id' => 1, 'name' => 'Magazin 1'],
            ['id' => 2, 'name' => 'Magazin 2'],
        ]);

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->postJson('/api/items', $this->samplePayload());

        $response->assertStatus(202);

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
        $this->assertDatabaseHas('prices', ['id' => 1]);

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

    public function test_unknown_shop_id_is_skipped_without_failing_the_batch(): void
    {
        Shop::insert(['id' => 1, 'name' => 'Magazin 1']);

        $payload = [[
            'id' => 456,
            'name' => 'Test item',
            'qty' => [
                ['shop' => ['id' => 1, 'name' => 'Magazin 1'], 'value' => 10],
                ['shop' => ['id' => 999, 'name' => 'Unknown shop'], 'value' => 20],
            ],
        ]];

        $response = $this
            ->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
            ->postJson('/api/items', $payload);

        $response->assertStatus(202);

        $this->assertDatabaseHas('items', ['id' => 456, 'name' => 'Test item']);
        $this->assertDatabaseHas('stocks', ['item_id' => 456, 'shop_id' => 1, 'qty' => '10.000']);
        $this->assertDatabaseMissing('stocks', ['item_id' => 456, 'shop_id' => 999]);
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
