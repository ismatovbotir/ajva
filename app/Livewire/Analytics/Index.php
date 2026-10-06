<?php

namespace App\Livewire\Analytics;

use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app', ['title' => 'Analytics'])]
class Index extends Component
{
    use WithPagination;

    private const PER_PAGE = 50;

    public string $date = '';

    public bool $generated = false;

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
        $this->resetPage();
    }

    public function generate(): void
    {
        $this->generated = true;
        $this->resetPage();
    }

    public function render()
    {
        $rows = $this->generated ? $this->buildRows() : collect();

        return view('livewire.analytics.index', [
            'rows' => new LengthAwarePaginator(
                $rows->forPage($this->getPage(), self::PER_PAGE)->values(),
                $rows->count(),
                self::PER_PAGE,
                $this->getPage(),
            ),
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
            ->groupBy('receipts.shop_id', 'receipt_items.item_id', 'shops.name', 'items.name', 'stocks.qty')
            ->get([
                'shops.name as shop_name',
                'items.name as item_name',
                'stocks.qty as stock_qty',
                DB::raw('SUM(CASE WHEN receipts.sell = 1 THEN receipt_items.qty ELSE -receipt_items.qty END) as sold_qty'),
            ])
            ->map(function ($row) {
                $stock = (float) $row->stock_qty;
                $sold = (float) $row->sold_qty;

                return [
                    'shop' => $row->shop_name,
                    'item' => $row->item_name,
                    'stock' => $stock,
                    'sold' => $sold,
                    'remaining' => $stock - $sold,
                ];
            })
            ->sortByDesc('remaining')
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
