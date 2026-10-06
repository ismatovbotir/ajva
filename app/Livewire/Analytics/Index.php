<?php

namespace App\Livewire\Analytics;

use App\Models\Receipt;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Analytics'])]
class Index extends Component
{
    private const CACHE_TTL = 300;

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
    }

    public function selectShop(int $shopId): void
    {
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

        if ($tabs->isNotEmpty() && ! $tabs->contains('id', $this->shopId)) {
            $this->shopId = $tabs->first()['id'];
        }

        // Only the active shop's rows are built, and they're reused across
        // tab switches / modal open-close until Generate is pressed again.
        $rows = $this->generated && $this->shopId !== null && $tabs->isNotEmpty()
            ? $this->cached('rows.'.$this->shopId, fn () => $this->buildRows($this->shopId))
            : collect();

        // Sorting is applied on top of the cached rows, so it costs no queries.
        $flags = $this->sortBy === 'item' ? SORT_NATURAL | SORT_FLAG_CASE : SORT_REGULAR;
        $rows = $rows->sortBy($this->sortBy, $flags, $this->sortDir === 'desc')->values();

        return view('livewire.analytics.index', [
            'tabs' => $tabs,
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
            "analytics.{$this->date}.{$this->runKey}.{$key}",
            self::CACHE_TTL,
            $callback,
        );
    }

    /**
     * Shops that have at least one successful, non-storno line on the day.
     */
    private function tabs()
    {
        [$from, $to] = $this->dayRange();

        return $this->cached('tabs', fn () => DB::table('shops')
            ->whereIn('shops.id', function ($q) use ($from, $to) {
                $q->from('receipts')
                    ->select('receipts.shop_id')
                    ->where('receipts.active', true)
                    ->whereBetween('receipts.created_at', [$from, $to])
                    ->whereExists(function ($e) {
                        $e->from('receipt_items')
                            ->whereColumn('receipt_items.receipt_id', 'receipts.id')
                            ->where('receipt_items.storno', false);
                    });
            })
            ->orderBy('shops.name')
            ->get(['shops.id', 'shops.name'])
            ->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name])
            ->values());
    }

    /**
     * Net sold qty per item for one shop — successful sale receipts add,
     * successful refund receipts subtract (storno lines are ignored) —
     * compared against the shop's current stock qty. Read-only: stocks are
     * never modified, 1C stays the source of truth for them.
     *
     * The aggregate groups by item id only; names, stock and min/max are
     * then fetched by key (PK / unique-index lookups) instead of being
     * joined and grouped as wide varchar columns.
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

        $ids = $sold->keys();

        $names = collect();
        $stocks = collect();
        $rules = collect();
        foreach ($ids->chunk(500) as $chunk) {
            $chunk = $chunk->all();
            $names = $names->union(DB::table('items')
                ->leftJoin('groups', 'groups.id', '=', 'items.group_id')
                ->whereIn('items.id', $chunk)
                ->get(['items.id', 'items.name', 'groups.name as group_name'])
                ->keyBy('id'));
            $stocks = $stocks->union(DB::table('stocks')->where('shop_id', $shopId)->whereIn('item_id', $chunk)->pluck('qty', 'item_id'));
            $rules = $rules->union(DB::table('item_order_rules')->where('shop_id', $shopId)->whereIn('item_id', $chunk)->get(['item_id', 'min', 'max'])->keyBy('item_id'));
        }

        return $sold->map(function ($soldQty, $itemId) use ($names, $stocks, $rules) {
            $stock = (float) ($stocks[$itemId] ?? 0);
            $net = (float) $soldQty;
            $rule = $rules[$itemId] ?? null;

            return [
                'item_id' => (int) $itemId,
                'group' => $names[$itemId]->group_name ?? null,
                'item' => $names[$itemId]->name ?? '#'.$itemId,
                'stock' => $stock,
                'sold' => $net,
                'remaining' => $stock - $net,
                'min' => $rule ? (float) $rule->min : null,
                'max' => $rule ? (float) $rule->max : null,
            ];
        })->values();
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
