<?php

namespace Tests\Concerns;

use App\Enums\MonitorType;
use App\Models\Item;
use App\Models\ItemOrderRule;
use App\Models\ItemPrice;
use App\Models\Monitor;
use App\Models\Pos;
use App\Models\Price;
use App\Models\Receipt;
use App\Models\ReceiptItem;
use App\Models\ReceiptPayment;
use App\Models\Shop;
use App\Models\Stock;
use App\Monitor\Grid;
use App\Monitor\WidgetCatalog;
use App\Support\MonitorScope;
use Illuminate\Support\Str;

/** Helpers for the custom monitor tests: layouts, scopes and a small receipts/stock fixture. */
trait BuildsCustomMonitors
{
    /**
     * @param  array<int, string|array{0: string, 1?: string}>  $keys  catalog keys or [key, diagram]
     * @param  array<string, mixed>  $attributes
     */
    protected function customMonitor(array $keys = [], array $attributes = [], array $shops = []): Monitor
    {
        $layout = [];
        foreach ($keys as $entry) {
            [$key, $diagram] = is_array($entry) ? [$entry[0], $entry[1] ?? null] : [$entry, null];
            $def = WidgetCatalog::find($key);
            $layout = Grid::add($layout, $def, (string) Str::uuid());
            if ($diagram !== null) {
                $layout[array_key_last($layout)]['diagram'] = $diagram;
            }
        }

        $monitor = Monitor::factory()->type(MonitorType::Custom)->create($attributes + ['layout' => $layout]);
        $monitor->shops()->sync($shops);

        return $monitor;
    }

    protected function scopeFor(Monitor $monitor, ?array $shopIds = null, bool $profit = false, bool $public = false): MonitorScope
    {
        return new MonitorScope($monitor, $shopIds, $profit, $public, now());
    }

    /** A receipt with $lines positions (and a storno line that must never count). */
    protected function sale(Shop $shop, string $at, float $total, array $attrs = [], int $lines = 1, ?Item $item = null, float $qty = 1): Receipt
    {
        $receipt = Receipt::factory()->create(array_merge([
            'shop_id' => $shop->id,
            'pos_id' => Pos::query()->where('shop_id', $shop->id)->value('id') ?? Pos::factory()->create(['shop_id' => $shop->id])->id,
            'active' => true, 'sell' => true, 'total' => $total, 'discount' => 0, 'created_at' => $at,
        ], $attrs));
        ReceiptItem::factory()->count($lines)->create(array_filter([
            'receipt_id' => $receipt->id, 'storno' => false, 'total' => $total / max($lines, 1), 'qty' => $qty,
            'item_id' => $item?->id,
        ], fn ($v) => $v !== null));
        ReceiptPayment::factory()->create(['receipt_id' => $receipt->id, 'payment' => 'cash', 'value' => $total]);

        return $receipt;
    }

    /** $n sale receipts of $total each, one per minute from $from (HH:MM:SS on $day). */
    protected function sales(Shop $shop, string $day, string $from, int $n, float $total, ?Item $item = null): void
    {
        $t = strtotime("{$day} {$from}");
        for ($i = 0; $i < $n; $i++) {
            $this->sale($shop, date('Y-m-d H:i:s', $t + $i * 60), $total, [], 1, $item);
        }
    }

    protected function ruledItem(Shop $shop, float $qty, float $min = 5, float $max = 20, ?string $name = null): Item
    {
        $item = Item::factory()->create($name ? ['name' => $name] : []);
        ItemOrderRule::factory()->create(['item_id' => $item->id, 'shop_id' => $shop->id, 'min' => $min, 'max' => $max]);
        Stock::factory()->create(['item_id' => $item->id, 'shop_id' => $shop->id, 'qty' => $qty]);

        return $item;
    }

    protected function cost(Item $item, float $value): void
    {
        $price = Price::query()->find(config('inventory.cost_price_id'))
            ?? Price::factory()->create(['id' => config('inventory.cost_price_id'), 'name' => 'Cost', 'is_sell' => false]);
        ItemPrice::factory()->create(['item_id' => $item->id, 'price_id' => $price->id, 'value' => $value]);
    }

    /** @return array<string, mixed> the payload of the first widget with $key */
    protected function payload(array $result, string $key): ?array
    {
        foreach ($result['widgets'] as $widget) {
            if ($widget['key'] === $key) {
                return $widget;
            }
        }

        return null;
    }
}
