<?php

namespace App\Livewire\Analytics;

use App\Models\Receipt;
use App\Models\Stock;
use App\Support\ShopAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Analytics'])]
class Index extends Component
{
    /** A generated report stays readable (tab switches, sorting) for an hour. */
    private const CACHE_TTL = 3600;

    /** Row keys the column headers can sort by. */
    private const SORTABLE = ['item', 'stock', 'sold', 'remaining'];

    public string $date = '';

    public bool $generated = false;

    /** Changes on every Generate press; part of the result-cache key. */
    public string $runKey = '';

    public ?int $shopId = null;

    public ?int $modalItemId = null;

    public string $sortBy = 'remaining';

    public string $sortDir = 'asc';

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
        $this->runKey = bin2hex(random_bytes(4));
        $this->modalItemId = null;

        // Build every shop's table up front (the "Generating report" modal covers this
        // wait), so switching tabs or sorting afterwards only reads the cached result
        // and never recomputes anything.
        foreach ($this->tabs() as $tab) {
            $this->cached('rows.'.$tab['id'], fn () => $this->buildRows($tab['id']));
        }
    }

    public function selectShop(int $shopId): void
    {
        // Never trust the client: a shop outside the user's scope is a 404.
        ShopAccess::authorize($shopId);

        $this->shopId = $shopId;
        $this->modalItemId = null;
    }

    public function sort(string $column): void
    {
        if (! in_array($column, self::SORTABLE, true)) {
            return;
        }

        if ($this->sortBy === $column) {
            $this->sortDir = $this->sortDir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $column;
            $this->sortDir = 'asc';
        }
    }

    public function showReceipts(int $itemId): void
    {
        ShopAccess::authorize($this->shopId);

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
        if ($this->modalItemId === null || $this->shopId === null || ! ShopAccess::allows($this->shopId)) {
            return null;
        }

        [$from, $to] = $this->dayRange();

        return Receipt::query()
            ->where('shop_id', $this->shopId)
            ->where('active', true)
            ->whereBetween('created_at', [$from, $to])
            ->whereHas('items', fn ($q) => $q->where('item_id', $this->modalItemId)->where('storno', false))
            ->orderByDesc('created_at')
            ->get(['id', 'number', 'cashier', 'active', 'sell', 'total', 'created_at']);
    }

    public function render()
    {
        $tabs = $this->generated ? $this->tabs() : collect();

        // A forged/stale shop id (outside the user's tabs) falls back to the first allowed tab.
        if ($tabs->isNotEmpty() && ! $tabs->contains('id', $this->shopId)) {
            $this->shopId = $tabs->first()['id'];
        }

        // The rows were all built by generate(); this normally just reads the cache
        // (and only recomputes if the cached report has expired).
        $rows = $this->generated && $this->shopId !== null && $tabs->isNotEmpty()
            ? $this->cached('rows.'.$this->shopId, fn () => $this->buildRows($this->shopId))
            : collect();

        // Sorting is applied on top of the cached rows, so it costs no queries.
        $flags = $this->sortBy === 'item' ? SORT_NATURAL | SORT_FLAG_CASE : SORT_REGULAR;
        $rows = $rows->sortBy($this->sortBy, $flags, $this->sortDir === 'desc')->values();

        return view('livewire.analytics.index', [
            'tabs' => $tabs,
            'noShops' => ShopAccess::hasNone(),
            'rows' => $rows,
            'sortBy' => $this->sortBy,
            'sortDir' => $this->sortDir,
            'modalReceipts' => $this->modalReceipts(),
            'modalItemName' => $rows->firstWhere('item_id', $this->modalItemId)['item'] ?? null,
        ]);
    }

    /**
     * Result cache scoped to the date and the last Generate press, so a
     * tab switch or modal toggle doesn't re-run the aggregate queries but
     * pressing Generate always recomputes from fresh data.
     */
    private function cached(string $key, \Closure $callback)
    {
        return Cache::remember(
            'analytics.'.ShopAccess::scopeKey(ShopAccess::ids()).".{$this->date}.{$this->runKey}.{$key}",
            self::CACHE_TTL,
            $callback,
        );
    }

    /**
     * One tab per shop: every item is listed for every shop, whether or not
     * it sold that day.
     */
    private function tabs()
    {
        return $this->cached('tabs', fn () => ShopAccess::restrict(DB::table('shops'), 'id')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])
            ->values());
    }

    /**
     * Every item (the base of the report) left-joined to the shop's stock and
     * min/max rule and to the day's net sold qty — successful sale receipts
     * add, successful refund receipts subtract, storno lines are ignored. An
     * item with no stock row or no sales shows 0. Read-only: stocks are never
     * modified, 1C stays the source of truth for them.
     *
     * Sales are aggregated by item id in a separate query and merged in PHP,
     * so the main query stays a plain left join with no GROUP BY.
     */
    private function buildRows(int $shopId)
    {
        [$from, $to] = $this->dayRange();

        $sold = DB::table('receipt_items')
            ->join('receipts', 'receipts.id', '=', 'receipt_items.receipt_id')
            ->where('receipts.shop_id', $shopId)
            ->where('receipts.active', true)
            ->where('receipt_items.storno', false)
            ->whereBetween('receipts.created_at', [$from, $to])
            ->groupBy('receipt_items.item_id')
            ->selectRaw('receipt_items.item_id, SUM(CASE WHEN receipts.sell = 1 THEN receipt_items.qty ELSE -receipt_items.qty END) as sold_qty')
            ->pluck('sold_qty', 'item_id');

        return DB::table('items')
            ->leftJoin('groups', 'groups.id', '=', 'items.group_id')
            ->leftJoin('stocks', function ($join) use ($shopId, $from) {
                $join->on('stocks.item_id', '=', 'items.id')->where('stocks.shop_id', '=', $shopId);
                // The report has a date picker: stock is the latest snapshot on or before that day.
                Stock::applyAsOf($join, $from->toDateString());
            })
            ->leftJoin('item_order_rules', function ($join) use ($shopId) {
                $join->on('item_order_rules.item_id', '=', 'items.id')->where('item_order_rules.shop_id', '=', $shopId);
            })
            ->get([
                'items.id as item_id',
                'items.name as item_name',
                'groups.name as group_name',
                'stocks.qty as stock_qty',
                'stocks.stock_date as stock_date',
                'item_order_rules.min as rule_min',
                'item_order_rules.max as rule_max',
            ])
            ->map(function ($row) use ($sold) {
                $stock = (float) $row->stock_qty;
                $net = (float) ($sold[$row->item_id] ?? 0);

                return [
                    'item_id' => (int) $row->item_id,
                    'group' => $row->group_name,
                    'item' => $row->item_name,
                    'stock' => $stock,
                    'stock_date' => $row->stock_date,
                    'sold' => $net,
                    'remaining' => $stock - $net,
                    'min' => $row->rule_min === null ? null : (float) $row->rule_min,
                    'max' => $row->rule_max === null ? null : (float) $row->rule_max,
                ];
            })
            ->values();
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function dayRange(): array
    {
        $day = $this->parsedDate() ?? now()->startOfDay();

        return [$day->copy()->startOfDay(), $day->copy()->endOfDay()];
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
