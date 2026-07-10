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
use Illuminate\Support\Facades\Log;

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

        $knownShopIds = $this->knownShopIds();

        $this->upsertPrices();
        $this->upsertItemPrices();
        $this->upsertStocks($knownShopIds);
        $this->upsertItemOrderRules($knownShopIds);
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

    /**
     * @return array<int, bool> a set of known shop ids, keyed by id, for O(1) lookups
     */
    protected function knownShopIds(): array
    {
        $referencedShopIds = [];

        foreach ($this->items as $item) {
            foreach (($item['qty'] ?? []) as $qty) {
                $referencedShopIds[] = $qty['shop']['id'];
            }

            foreach (($item['order'] ?? []) as $order) {
                $referencedShopIds[] = $order['shop']['id'];
            }
        }

        if ($referencedShopIds === []) {
            return [];
        }

        return Shop::query()
            ->whereIn('id', array_unique($referencedShopIds))
            ->pluck('id')
            ->flip()
            ->map(fn () => true)
            ->all();
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

    /**
     * @param  array<int, bool>  $knownShopIds
     */
    protected function upsertStocks(array $knownShopIds): void
    {
        $rows = [];

        foreach ($this->items as $item) {
            foreach (($item['qty'] ?? []) as $qty) {
                $shopId = $qty['shop']['id'];

                if (! isset($knownShopIds[$shopId])) {
                    Log::warning("ProcessLocalItemsBatch: unknown shop id [{$shopId}] in qty for item [{$item['id']}], skipping.");

                    continue;
                }

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

    /**
     * @param  array<int, bool>  $knownShopIds
     */
    protected function upsertItemOrderRules(array $knownShopIds): void
    {
        $rows = [];

        foreach ($this->items as $item) {
            foreach (($item['order'] ?? []) as $order) {
                $shopId = $order['shop']['id'];

                if (! isset($knownShopIds[$shopId])) {
                    Log::warning("ProcessLocalItemsBatch: unknown shop id [{$shopId}] in order for item [{$item['id']}], skipping.");

                    continue;
                }

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
