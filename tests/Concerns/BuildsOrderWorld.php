<?php

namespace Tests\Concerns;

use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\OrderRule;
use App\Models\Pos;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\Shop;
use App\Models\Stock;
use App\Support\Warehouse;
use Illuminate\Support\Carbon;

/**
 * A small world for the order-rule / automatic-draft tests: a main warehouse, shops, items with a stock history
 * (first snapshot long ago, a snapshot today) and an order rule. Frozen at 2026-10-07 12:00 (a Wednesday).
 */
trait BuildsOrderWorld
{
    protected Shop $a;

    protected Shop $b;

    protected Shop $wh;

    protected function buildWorld(): void
    {
        Carbon::setTestNow('2026-10-07 12:00:00');
        $this->a = Shop::factory()->create(['name' => 'Alpha']);
        $this->b = Shop::factory()->create(['name' => 'Beta']);
        $this->wh = Shop::factory()->create(['name' => 'Main warehouse']);
        Warehouse::setShopId($this->wh->id);
    }

    protected function tearDownWorld(): void
    {
        Carbon::setTestNow();
    }

    /** An item in a shop: snapshots on $firstSeen and today (skipped for 'today' only), an item order rule min/max. */
    protected function item(string $name, Shop $shop, float $stock, float $min, float $max, string $firstSeen = '2026-09-01'): Item
    {
        $item = Item::factory()->create(['name' => $name]);
        Stock::query()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => $stock, 'stock_date' => $firstSeen]);
        if ($firstSeen !== '2026-10-07') {
            Stock::query()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'qty' => $stock, 'stock_date' => '2026-10-07']);
        }
        ItemOrderRule::query()->create(['shop_id' => $shop->id, 'item_id' => $item->id, 'min' => $min, 'max' => $max]);

        return $item;
    }

    protected function warehouseHas(Item $item, float $qty): void
    {
        Stock::query()->create(['shop_id' => $this->wh->id, 'item_id' => $item->id, 'qty' => $qty, 'stock_date' => '2026-10-07']);
    }

    protected function sell(Shop $shop, Item $item, float $qty, string $at): void
    {
        // One till per shop (the Pos factory only has 100 unique names).
        $pos = Pos::query()->firstOrCreate(['shop_id' => $shop->id], ['name' => 'Till '.$shop->id]);
        $receipt = Receipt::factory()->create(['shop_id' => $shop->id, 'pos_id' => $pos->id, 'created_at' => $at]);
        ReceiptItem::factory()->create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'qty' => $qty]);
    }

    protected function sellDaily(Shop $shop, Item $item, float $qty, int $days = 28): void
    {
        for ($i = 1; $i <= $days; $i++) {
            $this->sell($shop, $item, $qty, Carbon::now()->subDays($i)->setTime(11, 0)->toDateTimeString());
        }
    }

    /** The chain default rule: on, 08:00, every day, 28 days of history, horizon 3. */
    protected function defaultRule(array $attributes = []): OrderRule
    {
        return OrderRule::query()->create(array_merge([
            'shop_id' => null,
            'enabled' => true,
            'history_days' => 28,
            'horizon_days' => 3,
            'run_time' => '08:00',
            'weekdays' => [],
        ], $attributes));
    }
}
