<?php

namespace App\Jobs;

use App\Models\Barcode;
use App\Models\Group;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\ItemPrice;
use App\Models\Price;
use App\Models\Shop;
use App\Models\Stock;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessLocalItemsBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(protected array $items) {}

    public function handle(): void
    {
        $now = now();

        $this->upsertGroups($now);
        $this->upsertItems($now);
        $this->syncBarcodes();
        $this->upsertShops();

        $this->upsertPrices();
        $this->upsertItemPrices();
        $this->upsertStocks();
        $this->upsertItemOrderRules();
    }

    protected function upsertGroups($now): void
    {
        $groups = [];

        foreach ($this->items as $item) {
            if (! empty($item['group']['id'])) {
                $groups[$item['group']['id']] = [
                    'id' => $item['group']['id'],
                    'name' => $item['group']['name'] ?? '',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($groups !== []) {
            Group::upsert(array_values($groups), ['id'], ['name', 'updated_at']);
        }
    }

    protected function upsertItems($now): void
    {
        $rows = [];

        foreach ($this->items as $item) {
            $rows[$item['id']] = [
                'id' => $item['id'],
                'group_id' => $item['group']['id'] ?? null,
                'mark' => $item['mark'] ?? null,
                'name' => $item['name'],
                'class_code' => $item['class_code'] ?? null,
                'package_code' => $item['package_code'] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            Item::upsert(
                array_values($rows),
                ['id'],
                ['group_id', 'mark', 'name', 'class_code', 'package_code', 'updated_at']
            );
        }
    }

    protected function syncBarcodes(): void
    {
        $upsertRows = [];

        foreach ($this->items as $item) {
            $incoming = $item['barcode'] ?? [];

            // 1C sends the complete current set of barcodes per item on every
            // sync, so anything not in the incoming set is stale and removed.
            Barcode::where('item_id', $item['id'])->whereNotIn('gtin', $incoming)->delete();

            foreach ($incoming as $gtin) {
                $upsertRows[$gtin] = [
                    'item_id' => $item['id'],
                    'gtin' => $gtin,
                ];
            }
        }

        if ($upsertRows !== []) {
            Barcode::upsert(array_values($upsertRows), ['gtin'], ['item_id']);
        }
    }

    protected function upsertShops(): void
    {
        $shops = [];

        foreach ($this->items as $item) {
            foreach (($item['qty'] ?? []) as $qty) {
                $shopId = $qty['shop']['id'];
                $shops[$shopId] = [
                    'id' => $shopId,
                    'name' => $qty['shop']['name'] ?? '',
                ];
            }

            foreach (($item['order'] ?? []) as $order) {
                $shopId = $order['shop']['id'];
                $shops[$shopId] = [
                    'id' => $shopId,
                    'name' => $order['shop']['name'] ?? '',
                ];
            }
        }

        if ($shops !== []) {
            Shop::upsert(array_values($shops), ['id'], ['name']);
        }
    }

    protected function upsertPrices(): void
    {
        $rows = [];

        foreach ($this->items as $item) {
            foreach (($item['price'] ?? []) as $priceEntry) {
                $priceId = $priceEntry['price']['id'];
                $rows[$priceId] = [
                    'id' => $priceId,
                    'name' => $priceEntry['price']['name'] ?? '',
                ];
            }
        }

        if ($rows !== []) {
            Price::upsert(array_values($rows), ['id'], ['name']);
        }
    }

    protected function upsertItemPrices(): void
    {
        $rows = [];

        foreach ($this->items as $item) {
            foreach (($item['price'] ?? []) as $priceEntry) {
                $key = $item['id'].'-'.$priceEntry['price']['id'];
                $rows[$key] = [
                    'item_id' => $item['id'],
                    'price_id' => $priceEntry['price']['id'],
                    'value' => $priceEntry['value'],
                ];
            }
        }

        if ($rows !== []) {
            ItemPrice::upsert(array_values($rows), ['item_id', 'price_id'], ['value']);
        }
    }

    protected function upsertStocks(): void
    {
        $rows = [];

        foreach ($this->items as $item) {
            foreach (($item['qty'] ?? []) as $qty) {
                $shopId = $qty['shop']['id'];

                $key = $item['id'].'-'.$shopId;
                $rows[$key] = [
                    'item_id' => $item['id'],
                    'shop_id' => $shopId,
                    'qty' => $qty['value'],
                ];
            }
        }

        if ($rows !== []) {
            Stock::upsert(array_values($rows), ['item_id', 'shop_id'], ['qty']);
        }
    }

    protected function upsertItemOrderRules(): void
    {
        $rows = [];

        foreach ($this->items as $item) {
            foreach (($item['order'] ?? []) as $order) {
                $shopId = $order['shop']['id'];

                $key = $item['id'].'-'.$shopId;
                $rows[$key] = [
                    'item_id' => $item['id'],
                    'shop_id' => $shopId,
                    'min' => $order['min'],
                    'max' => $order['max'],
                ];
            }
        }

        if ($rows !== []) {
            ItemOrderRule::upsert(array_values($rows), ['item_id', 'shop_id'], ['min', 'max']);
        }
    }
}
