<?php

namespace App\Livewire\Analytics;

use App\Models\Receipt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Analytics'])]
class Index extends Component
{
    public string $date = '';

    public bool $generated = false;

    public ?int $shopId = null;

    public ?int $modalItemId = null;

    public function mount(): void
    {
        $this->date = now()->toDateString();
    }

    public function updatedDate(): void
    {
        if (! $this->parsedDate()) {
            $this->date = now()->toDateString();
        }

        $this->generated = false;
        $this->modalItemId = null;
    }

    public function generate(): void
    {
        $this->generated = true;
    }

    public function selectShop(int $shopId): void
    {
        $this->shopId = $shopId;
        $this->modalItemId = null;
    }

    public function showReceipts(int $itemId): void
    {
        $this->modalItemId = $itemId;
    }

    public function closeModal(): void
    {
        $this->modalItemId = null;
    }

    /**
     * The receipts behind a "net sold" cell: the day's successful receipts
     * for the active shop that contain the item (storno lines excluded).
     */
    private function modalReceipts()
    {
        if ($this->modalItemId === null || $this->shopId === null) {
            return null;
        }

        $day = $this->parsedDate() ?? now()->startOfDay();

        return Receipt::query()
            ->where('shop_id', $this->shopId)
            ->where('active', true)
            ->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->whereHas('items', fn ($q) => $q->where('item_id', $this->modalItemId)->where('storno', false))
            ->orderByDesc('created_at')
            ->get(['id', 'number', 'cashier', 'active', 'sell', 'total', 'created_at']);
    }

    public function render()
    {
        $all = $this->generated ? $this->buildRows() : collect();

        $tabs = $all->unique('shop_id')->sortBy('shop')->map(fn ($r) => [
            'id' => $r['shop_id'],
            'name' => $r['shop'],
        ])->values();

        if ($tabs->isNotEmpty() && ! $tabs->contains('id', $this->shopId)) {
            $this->shopId = $tabs->first()['id'];
        }

        $rows = $all->where('shop_id', $this->shopId)->values();

        return view('livewire.analytics.index', [
            'tabs' => $tabs,
            'rows' => $rows,
            'modalReceipts' => $this->modalReceipts(),
            'modalItemName' => $rows->firstWhere('item_id', $this->modalItemId)['item'] ?? null,
        ]);
    }

    /**
     * Net sold qty per (shop, item) for the day — successful sale receipts
     * add, successful refund receipts subtract (storno lines are ignored) —
     * compared against the shop's current stock qty. Read-only: stocks are
     * never modified, 1C stays the source of truth for them.
     */
    private function buildRows()
    {
        $day = $this->parsedDate() ?? now()->startOfDay();

        return DB::table('receipt_items')
            ->join('receipts', 'receipts.id', '=', 'receipt_items.receipt_id')
            ->join('items', 'items.id', '=', 'receipt_items.item_id')
            ->join('shops', 'shops.id', '=', 'receipts.shop_id')
            ->leftJoin('stocks', function ($join) {
                $join->on('stocks.item_id', '=', 'receipt_items.item_id')
                    ->on('stocks.shop_id', '=', 'receipts.shop_id');
            })
            ->where('receipts.active', true)
            ->where('receipt_items.storno', false)
            ->whereBetween('receipts.created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->leftJoin('item_order_rules', function ($join) {
                $join->on('item_order_rules.item_id', '=', 'receipt_items.item_id')
                    ->on('item_order_rules.shop_id', '=', 'receipts.shop_id');
            })
            ->groupBy('receipts.shop_id', 'receipt_items.item_id', 'shops.name', 'items.name', 'stocks.qty', 'item_order_rules.min', 'item_order_rules.max')
            ->get([
                'receipts.shop_id as shop_id',
                'receipt_items.item_id as item_id',
                'shops.name as shop_name',
                'items.name as item_name',
                'stocks.qty as stock_qty',
                'item_order_rules.min as rule_min',
                'item_order_rules.max as rule_max',
                DB::raw('SUM(CASE WHEN receipts.sell = 1 THEN receipt_items.qty ELSE -receipt_items.qty END) as sold_qty'),
            ])
            ->map(function ($row) {
                $stock = (float) $row->stock_qty;
                $sold = (float) $row->sold_qty;

                return [
                    'shop_id' => (int) $row->shop_id,
                    'shop' => $row->shop_name,
                    'item_id' => (int) $row->item_id,
                    'item' => $row->item_name,
                    'stock' => $stock,
                    'sold' => $sold,
                    'remaining' => $stock - $sold,
                    'min' => $row->rule_min === null ? null : (float) $row->rule_min,
                    'max' => $row->rule_max === null ? null : (float) $row->rule_max,
                ];
            })
            ->sortBy('remaining')
            ->values();
    }

    private function parsedDate(): ?Carbon
    {
        try {
            return Carbon::createFromFormat('Y-m-d', $this->date)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }
}
