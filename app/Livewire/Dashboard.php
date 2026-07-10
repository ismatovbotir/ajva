<?php

namespace App\Livewire;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Dashboard'])]
class Dashboard extends Component
{
    /**
     * Categorical palette — one hue per series/entity. Slot 1 is also the
     * single "primary" color used for non-comparative (one series) bars.
     */
    private const CATEGORY_COLORS = [
        '#2a78d6', // slot 1 — blue
        '#1baf7a', // slot 2 — teal/green
        '#eda100', // slot 3 — amber
        '#008300', // slot 4 — green
        '#4a3aa7', // slot 5 — purple
        '#c23a3a', // slot 6 — red (chart 3 only, extends beyond the 5 given donut slots)
        '#a13a7a', // slot 7 — magenta (chart 3 only, extends beyond the 5 given donut slots)
    ];

    /** Muted neutral used only for "Other" fold-in segments, never a real series color. */
    private const COLOR_OTHER = '#898781';

    public function render()
    {
        $byShop = $this->stockByShop();
        $byGroup = $this->stockByGroup();

        $shopMax = (float) ($byShop->max('total') ?: 1);
        $groupMax = (float) ($byGroup->max('total') ?: 1);

        $shopRows = $byShop->map(function (array $row) use ($shopMax) {
            $row['percent'] = $shopMax > 0 ? round($row['total'] / $shopMax * 100, 3) : 0;
            $row['label'] = $this->formatQty($row['total']);

            return $row;
        })->values()->all();

        $groupRows = $byGroup->map(function (array $row) use ($groupMax) {
            $row['percent'] = $groupMax > 0 ? round($row['total'] / $groupMax * 100, 3) : 0;
            $row['label'] = $this->formatQty($row['total']);

            return $row;
        })->values()->all();

        return view('livewire.dashboard', [
            'shopRows' => $shopRows,
            'shopMax' => $shopMax,
            'donut' => $this->buildDonut($byShop),
            'groupRows' => $groupRows,
            'groupMax' => $groupMax,
            'groupShop' => $this->stockByGroupAndShop($byShop, $byGroup),
            'barColor' => self::CATEGORY_COLORS[0],
        ]);
    }

    /**
     * Total stock quantity per shop, descending.
     *
     * @return Collection<int, array{id: int, name: string, total: float}>
     */
    private function stockByShop(): Collection
    {
        return DB::table('stocks')
            ->join('shops', 'shops.id', '=', 'stocks.shop_id')
            ->selectRaw('shops.id as id, shops.name as name, SUM(stocks.qty) as total')
            ->groupBy('shops.id', 'shops.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'name' => (string) $row->name,
                'total' => (float) $row->total,
            ]);
    }

    /**
     * Total stock quantity per item group, descending. Items with no group
     * (group_id IS NULL) roll up into a single "No group" bucket.
     *
     * @return Collection<int, array{id: int|null, name: string, total: float}>
     */
    private function stockByGroup(): Collection
    {
        return DB::table('stocks')
            ->join('items', 'items.id', '=', 'stocks.item_id')
            ->leftJoin('groups', 'groups.id', '=', 'items.group_id')
            ->selectRaw('items.group_id as id, groups.name as name, SUM(stocks.qty) as total')
            ->groupBy('items.group_id', 'groups.name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->id === null ? null : (int) $row->id,
                'name' => $row->id === null ? __('No group') : (string) $row->name,
                'total' => (float) $row->total,
            ]);
    }

    /**
     * Pivots total stock quantity per (group, shop) into "one row per group,
     * with a qty-by-shop stacked breakdown" shape for the stacked bar chart,
     * capped at the 7 highest-total shops (colors follow the shop across
     * every group's bar) with the remainder folded into "Other".
     */
    private function stockByGroupAndShop(Collection $byShop, Collection $byGroup): array
    {
        $raw = DB::table('stocks')
            ->join('items', 'items.id', '=', 'stocks.item_id')
            ->join('shops', 'shops.id', '=', 'stocks.shop_id')
            ->selectRaw('items.group_id as group_id, shops.id as shop_id, SUM(stocks.qty) as total')
            ->groupBy('items.group_id', 'shops.id')
            ->get();

        $shopTotalsByGroup = [];
        foreach ($raw as $row) {
            $groupKey = $row->group_id === null ? 'none' : (int) $row->group_id;
            $shopTotalsByGroup[$groupKey][(int) $row->shop_id] = (float) $row->total;
        }

        $topShops = $byShop->take(7)->values();
        $hasOther = $byShop->count() > $topShops->count();

        $legend = $topShops->map(fn (array $shop, int $i) => [
            'id' => $shop['id'],
            'name' => $shop['name'],
            'color' => self::CATEGORY_COLORS[$i],
        ])->values()->all();

        if ($hasOther) {
            $legend[] = ['id' => null, 'name' => __('Other'), 'color' => self::COLOR_OTHER];
        }

        $rows = [];
        $barMax = 0.0;

        foreach ($byGroup as $group) {
            $groupKey = $group['id'] === null ? 'none' : $group['id'];
            $shopTotals = $shopTotalsByGroup[$groupKey] ?? [];

            $segments = [];
            $cells = [];
            $otherTotal = 0.0;

            foreach ($topShops as $i => $shop) {
                $value = $shopTotals[$shop['id']] ?? 0.0;
                $cells[] = ['value' => $value, 'label' => $this->formatQty($value)];

                if ($value > 0) {
                    $segments[] = [
                        'name' => $shop['name'],
                        'color' => self::CATEGORY_COLORS[$i],
                        'value' => $value,
                        'percent_of_bar' => $group['total'] > 0 ? round($value / $group['total'] * 100, 3) : 0,
                        'label' => $this->formatQty($value),
                    ];
                }
            }

            foreach ($shopTotals as $shopId => $value) {
                if (! $topShops->contains('id', $shopId)) {
                    $otherTotal += $value;
                }
            }

            if ($hasOther) {
                $cells[] = ['value' => $otherTotal, 'label' => $this->formatQty($otherTotal)];

                if ($otherTotal > 0) {
                    $segments[] = [
                        'name' => __('Other'),
                        'color' => self::COLOR_OTHER,
                        'value' => $otherTotal,
                        'percent_of_bar' => $group['total'] > 0 ? round($otherTotal / $group['total'] * 100, 3) : 0,
                        'label' => $this->formatQty($otherTotal),
                    ];
                }
            }

            $barMax = max($barMax, $group['total']);

            $rows[] = [
                'id' => $group['id'],
                'name' => $group['name'],
                'total' => $group['total'],
                'total_label' => $this->formatQty($group['total']),
                'segments' => $segments,
                'cells' => $cells,
            ];
        }

        $barMax = $barMax ?: 1.0;

        foreach ($rows as &$row) {
            $row['bar_percent'] = round($row['total'] / $barMax * 100, 3);
        }
        unset($row);

        return [
            'rows' => $rows,
            'legend' => $legend,
            'max' => $barMax,
        ];
    }

    /**
     * Builds a donut ("share of total") view of stock-by-shop, capped at 6
     * segments: the top 5 shops by qty plus everything else folded into
     * "Other" — a full pie is illegible past ~6 segments.
     */
    private function buildDonut(Collection $byShop): array
    {
        $total = (float) $byShop->sum('total');

        if ($total <= 0) {
            return ['segments' => [], 'total' => 0.0, 'radius' => 60, 'circumference' => 0.0];
        }

        $radius = 60;
        $circumference = 2 * M_PI * $radius;

        $top5 = $byShop->take(5)->values();
        $otherTotal = (float) $byShop->slice(5)->sum('total');

        $cumulative = 0.0;
        $segments = [];

        foreach ($top5 as $i => $shop) {
            $percent = $shop['total'] / $total * 100;
            $len = $percent / 100 * $circumference;

            $segments[] = [
                'name' => $shop['name'],
                'value' => $shop['total'],
                'label' => $this->formatQty($shop['total']),
                'percent' => $percent,
                'color' => self::CATEGORY_COLORS[$i],
                'dasharray' => sprintf('%.3F %.3F', $len, $circumference - $len),
                'dashoffset' => -$cumulative,
            ];

            $cumulative += $len;
        }

        if ($otherTotal > 0) {
            $percent = $otherTotal / $total * 100;
            $len = $percent / 100 * $circumference;

            $segments[] = [
                'name' => __('Other'),
                'value' => $otherTotal,
                'label' => $this->formatQty($otherTotal),
                'percent' => $percent,
                'color' => self::COLOR_OTHER,
                'dasharray' => sprintf('%.3F %.3F', $len, $circumference - $len),
                'dashoffset' => -$cumulative,
            ];
        }

        return [
            'segments' => $segments,
            'total' => $total,
            'radius' => $radius,
            'circumference' => $circumference,
        ];
    }

    /**
     * Trims a summed quantity to a whole number when it has no meaningful
     * fractional part, otherwise keeps 2 decimals — avoids "30.000"-style
     * noise on chart labels for what are almost always whole-unit sums.
     */
    private function formatQty(float $value): string
    {
        if (abs($value - round($value)) < 0.005) {
            return (string) (int) round($value);
        }

        return number_format($value, 2, '.', '');
    }
}
