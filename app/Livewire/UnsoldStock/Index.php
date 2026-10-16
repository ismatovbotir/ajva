<?php

namespace App\Livewire\UnsoldStock;

use App\Services\UnsoldStock;
use App\Support\LocalAdvisor;
use App\Support\ProfitAccess;
use App\Support\ShopAccess;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stock without sales: items that are in stock but had no sale in the selected period (see App\Services\UnsoldStock for the
 * metric dictionary). Generate builds the full list once (cached per Generate press, scope and profit variant); the view
 * mode, search, group and minimum filters then run on that cached list, so they never recompute anything.
 */
#[Layout('components.layouts.app', ['title' => 'Stock without sales'])]
class Index extends Component
{
    use WithPagination;

    private const MAX_DAYS = 92;

    private const MIN_DAYS = 7;

    private const PER_PAGE = 50;

    private const CACHE_TTL = 600;

    public string $dateFrom = '';

    public string $dateTo = '';

    /** @var array<int, int|string> Selected shop ids; empty = every shop the user may see. */
    public array $shopIds = [];

    public bool $generated = false;

    /** Changes on every Generate press so every press builds fresh data. */
    public string $runKey = '';

    /** @var array<string, mixed> */
    public array $applied = [];

    /** 'item' = unsold in all the selected shops together, 'shop' = stock in a shop that did not sell the item there. */
    public string $mode = 'item';

    public string $search = '';

    public string $group = '';

    public string $minOnHand = '';

    public string $sort = 'on_hand';

    public function mount(): void
    {
        $this->dateTo = now()->subDay()->toDateString();
        $this->dateFrom = now()->subDays(60)->toDateString();
    }

    public function generate(): void
    {
        $this->validate([
            'dateFrom' => ['required', 'date_format:Y-m-d'],
            'dateTo' => ['required', 'date_format:Y-m-d', 'after_or_equal:dateFrom'],
            'shopIds' => ['array'],
            'shopIds.*' => ['integer'],
        ]);

        $days = Carbon::parse($this->dateFrom)->diffInDays(Carbon::parse($this->dateTo)) + 1;
        if ($days > self::MAX_DAYS) {
            $this->addError('dateTo', __('The period can not be longer than :days days.', ['days' => self::MAX_DAYS]));

            return;
        }
        if ($days < self::MIN_DAYS) {
            $this->addError('dateTo', __('The period must be at least :days days.', ['days' => self::MIN_DAYS]));

            return;
        }

        $this->applied = ['from' => $this->dateFrom, 'to' => $this->dateTo, 'shops' => array_map('intval', $this->shopIds)];
        $this->runKey = bin2hex(random_bytes(4));
        $this->generated = true;
        $this->resetPage();
    }

    public function updated(string $name): void
    {
        if ($name !== 'page' && in_array($name, ['mode', 'search', 'group', 'minOnHand', 'sort'], true)) {
            $this->resetPage();
        }
    }

    private function availableShops()
    {
        return ShopAccess::restrict(DB::table('shops'), 'id')->orderBy('name')->get(['id', 'name']);
    }

    /** Selected shops intersected with the viewer's scope (never trust client ids). */
    private function effectiveShopIds(array $selected): array
    {
        $allowed = $this->availableShops()->pluck('id')->map(fn ($id) => (int) $id)->all();

        return $selected === [] ? $allowed : array_values(array_intersect($selected, $allowed));
    }

    public function render()
    {
        $report = [];
        $notes = [];
        $paginator = new LengthAwarePaginator([], 0, self::PER_PAGE);
        $groups = [];

        if ($this->generated && $this->applied !== []) {
            $shopIds = $this->effectiveShopIds($this->applied['shops']);
            $withProfit = ProfitAccess::allowed();
            $to = Carbon::parse($this->applied['to']);
            $key = 'unsold-stock.'.ShopAccess::salesKey('u', $this->applied['from'].'.'.$this->applied['to'].'.'.ProfitAccess::profitKey($withProfit).'.'.$this->runKey, $shopIds);
            $report = Cache::remember($key, self::CACHE_TTL, fn () => app(UnsoldStock::class)->build(Carbon::parse($this->applied['from']), $to, $shopIds, $withProfit));
            $notes = LocalAdvisor::notes('unsold_stock', ['report' => $report]);

            $perShop = $this->mode === 'shop';
            $rows = $perShop ? $report['pairs'] : $report['items'];
            $groups = collect($report['items'])->pluck('group')->filter()->unique()->sort()->values()->all();

            $search = mb_strtolower(trim($this->search));
            $min = is_numeric($this->minOnHand) ? (float) $this->minOnHand : null;
            $rows = array_values(array_filter($rows, function (array $r) use ($search, $min) {
                return ($search === '' || str_contains(mb_strtolower($r['name']), $search))
                    && ($this->group === '' || ($r['group'] ?? '') === $this->group)
                    && ($min === null || $r['on_hand'] >= $min);
            }));
            usort($rows, match ($this->sort) {
                'name' => fn ($a, $b) => strcmp($a['name'], $b['name']),
                'value' => fn ($a, $b) => [$b['value'] ?? -1, $b['on_hand']] <=> [$a['value'] ?? -1, $a['on_hand']],
                default => fn ($a, $b) => $b['on_hand'] <=> $a['on_hand'],
            });

            $page = $this->getPage();
            $slice = array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE);

            // Last sale only for the rows of this page: it reads the whole receipt history of those items.
            $last = app(UnsoldStock::class)->lastSales(array_values(array_unique(array_map(fn ($r) => $r['id'] ?? $r['item_id'], $slice))), $shopIds, $to, $perShop);
            $slice = array_map(function (array $r) use ($last, $perShop, $to) {
                $id = $r['id'] ?? $r['item_id'];
                $date = $last[$perShop ? $id.':'.$r['shop_id'] : (string) $id] ?? null;
                $r['last_sale'] = $date;
                $r['days_since'] = $date === null ? null : (int) Carbon::parse($date)->diffInDays($to->copy()->startOfDay());

                return $r;
            }, $slice);

            $paginator = new LengthAwarePaginator($slice, count($rows), self::PER_PAGE, $page, ['path' => request()->url()]);
        }

        return view('livewire.unsold-stock.index', [
            'shops' => $this->availableShops(),
            'noShops' => ShopAccess::hasNone(),
            'report' => $report,
            'notes' => $notes,
            'rows' => $paginator,
            'groupOptions' => $groups,
        ]);
    }
}
