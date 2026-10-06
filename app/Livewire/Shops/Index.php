<?php

namespace App\Livewire\Shops;

use App\Models\Receipt;
use App\Models\Shop;
use App\Support\ShopAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Shops'])]
class Index extends Component
{
    use WithPagination;

    public function render()
    {
        $shops = ShopAccess::restrict(Shop::query(), 'shops.id')
            ->withCount('stocks as item_count')
            ->withSum('stocks as qty_total', 'qty')
            ->orderBy('name')
            ->paginate(20);

        // Today's receipts per shop: sell / refund = successful receipts,
        // cancel = unsuccessful ones (any direction).
        $today = Receipt::query()
            ->whereIn('shop_id', $shops->pluck('id'))
            ->whereBetween('created_at', [now()->startOfDay(), now()->endOfDay()])
            ->selectRaw('shop_id,
                SUM(CASE WHEN active = 1 AND sell = 1 THEN 1 ELSE 0 END) AS sell_count,
                SUM(CASE WHEN active = 1 AND sell = 1 THEN total ELSE 0 END) AS sell_sum,
                SUM(CASE WHEN active = 1 AND sell = 0 THEN 1 ELSE 0 END) AS refund_count,
                SUM(CASE WHEN active = 1 AND sell = 0 THEN total ELSE 0 END) AS refund_sum,
                SUM(CASE WHEN active = 0 THEN 1 ELSE 0 END) AS cancel_count')
            ->groupBy('shop_id')
            ->get()
            ->keyBy('shop_id');

        return view('livewire.shops.index', [
            'noShops' => ShopAccess::hasNone(),
            'shops' => $shops,
            'today' => $today,
        ]);
    }
}
