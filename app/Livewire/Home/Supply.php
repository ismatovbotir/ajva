<?php

namespace App\Livewire\Home;

use App\Models\Stock;
use App\Services\AttentionItems;
use App\Support\ShopAccess;
use App\Support\Warehouse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Supply block of the Home page. Admin: warehouse out-of-stock count, pick-list coverage, drafts waiting, failed 1C
 * syncs (the same figures as the attention panel). Operator: his drafts to confirm and the 10 items most below their
 * minimum in his shops (explicit zero rule: only pairs the latest 1C sync reported). No cost, profit or other shops.
 */
class Supply extends Component
{
    public function boot(): void
    {
        abort_unless(in_array(auth()->user()?->role?->value, ['admin', 'operator'], true), 403);
    }

    public function render()
    {
        $user = auth()->user();
        $report = app(AttentionItems::class)->report($user);
        $ids = ShopAccess::ids($user);

        return view('livewire.home.supply', [
            'admin' => $user->isAdmin(),
            'facts' => $report['facts'],
            'checkedAt' => $report['checked_at'],
            'lowItems' => $user->isAdmin() ? [] : $this->lowItems($ids),
            'noShops' => $ids === [],
        ]);
    }

    /**
     * Ruled pairs at or below their minimum, most urgent first (qty / min), top 10.
     *
     * @param  array<int, int>|null  $ids
     * @return array<int, array<string, mixed>>
     */
    private function lowItems(?array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return Cache::remember(ShopAccess::salesKey('home.low-items', now()->toDateString(), $ids), 60, function () use ($ids) {
            $warehouseId = Warehouse::shopId();

            return Stock::applyReported(Stock::applyCurrent(ShopAccess::restrictTo(DB::table('stocks'), 'stocks.shop_id', $ids)))
                ->join('item_order_rules', function ($join) {
                    $join->on('item_order_rules.item_id', '=', 'stocks.item_id')->on('item_order_rules.shop_id', '=', 'stocks.shop_id');
                })
                ->join('items', 'items.id', '=', 'stocks.item_id')
                ->join('shops', 'shops.id', '=', 'stocks.shop_id')
                ->where('item_order_rules.min', '>', 0)
                ->whereColumn('stocks.qty', '<=', 'item_order_rules.min')
                ->when($warehouseId !== null, fn ($q) => $q->where('stocks.shop_id', '!=', $warehouseId))
                ->orderByRaw('(stocks.qty * 1.0) / item_order_rules.min')
                ->orderBy('items.name')
                ->limit(10)
                ->get(['items.id as item_id', 'items.name as item_name', 'shops.name as shop_name', 'stocks.qty as on_hand', 'item_order_rules.min as rule_min'])
                ->map(fn ($r) => [
                    'item_id' => (int) $r->item_id,
                    'item' => (string) $r->item_name,
                    'shop' => (string) $r->shop_name,
                    'qty' => (float) $r->on_hand,
                    'min' => (float) $r->rule_min,
                    'out' => (float) $r->on_hand <= 0,
                ])->all();
        });
    }
}
