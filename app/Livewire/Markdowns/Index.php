<?php

namespace App\Livewire\Markdowns;

use App\Services\Markdowns;
use App\Support\ProfitAccess;
use App\Support\ShopAccess;
use App\Support\Warehouse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Sales growth > Markdown and promo: markdown candidates (slow stock) and the effectiveness of discounts inferred from
 * receipts. Nothing is calculated until Generate is pressed; see App\Services\Markdowns for the metric dictionary.
 * Every figure is a signal to review and a suggested start to test and adjust.
 */
#[Layout('components.layouts.app', ['title' => 'Markdown and promo'])]
class Index extends Component
{
    use WithPagination;

    private const PER_PAGE = 50;

    public string $dateFrom = '';

    public string $dateTo = '';

    /** @var array<int, int|string> Selected shop ids; empty = every shop the user may see. */
    public array $shopIds = [];

    public bool $generated = false;

    /** Changes on every Generate press so every press builds fresh data. */
    public string $runKey = '';

    /** @var array<string, mixed> */
    public array $applied = [];

    /** 'candidates' or 'discounts'. */
    public string $tab = 'candidates';

    public string $search = '';

    public string $group = '';

    public string $shopFilter = '';

    public string $reason = '';

    public string $minValue = '';

    public string $sort = 'value';

    public function mount(): void
    {
        $days = (int) config('markdown.default_days', 60);
        $this->dateTo = now()->subDay()->toDateString();
        $this->dateFrom = now()->subDays($days)->toDateString();
    }

    public function generate(): void
    {
        $this->validate([
            'dateFrom' => ['required', 'date_format:Y-m-d'],
            'dateTo' => ['required', 'date_format:Y-m-d', 'after_or_equal:dateFrom'],
            'shopIds' => ['array'],
            'shopIds.*' => ['integer'],
        ]);

        // Whole days only: today is not finished yet.
        if (Carbon::parse($this->dateTo)->gte(now()->startOfDay())) {
            $this->addError('dateTo', __('Only whole days are used: the last day can be yesterday.'));

            return;
        }

        $min = (int) config('markdown.min_days', 7);
        $max = (int) config('markdown.max_days', 92);
        $days = Carbon::parse($this->dateFrom)->diffInDays(Carbon::parse($this->dateTo)) + 1;
        if ($days > $max) {
            $this->addError('dateTo', __('The period can not be longer than :days days.', ['days' => $max]));

            return;
        }
        if ($days < $min) {
            $this->addError('dateTo', __('The period must be at least :days days.', ['days' => $min]));

            return;
        }

        $this->applied = ['from' => $this->dateFrom, 'to' => $this->dateTo, 'shops' => array_map('intval', $this->shopIds)];
        $this->runKey = bin2hex(random_bytes(4));
        $this->generated = true;
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab === 'discounts' ? 'discounts' : 'candidates';
        $this->resetPage();
    }

    public function updated(string $name): void
    {
        if ($name !== 'page' && in_array($name, ['search', 'group', 'shopFilter', 'reason', 'minValue', 'sort'], true)) {
            $this->resetPage();
        }
    }

    /** Shops the user may pick: in scope, not disabled, not the main warehouse. */
    private function availableShops()
    {
        return ShopAccess::restrict(DB::table('shops')->where('is_disabled', false), 'id')
            ->when(Warehouse::shopId() !== null, fn ($q) => $q->where('id', '!=', Warehouse::shopId()))
            ->orderBy('name')->get(['id', 'name']);
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
        $rows = new LengthAwarePaginator([], 0, self::PER_PAGE);
        $delist = [];
        $groupOptions = [];
        $withProfit = ProfitAccess::allowed();

        if ($this->generated && $this->applied !== []) {
            $shopIds = $this->effectiveShopIds($this->applied['shops']);
            $from = Carbon::parse($this->applied['from']);
            $to = Carbon::parse($this->applied['to']);
            $suffix = $this->applied['from'].'.'.$this->applied['to'].'.'.ProfitAccess::profitKey($withProfit).'.'.$this->runKey;
            $ttl = (int) config('markdown.cache_seconds', 600);

            if ($this->tab === 'discounts') {
                $key = 'markdowns.'.ShopAccess::salesKey('d', $suffix, $shopIds);
                $report = Cache::remember($key, $ttl, fn () => app(Markdowns::class)->discounts($from, $to, $shopIds));
                $list = $report['rows'] ?? [];
            } else {
                $key = 'markdowns.'.ShopAccess::salesKey('c', $suffix, $shopIds);
                $report = Cache::remember($key, $ttl, fn () => app(Markdowns::class)->candidates($from, $to, $shopIds, $withProfit));
                $list = $this->filterCandidates($report['rows'] ?? []);
                $delist = $report['delist'] ?? [];
                $groupOptions = $report['groups'] ?? [];
            }

            $page = $this->getPage();
            $rows = new LengthAwarePaginator(array_slice($list, ($page - 1) * self::PER_PAGE, self::PER_PAGE), count($list), self::PER_PAGE, $page, ['path' => request()->url()]);
        }

        return view('livewire.markdowns.index', [
            'shops' => $this->availableShops(),
            'noShops' => ShopAccess::hasNone(),
            'report' => $report,
            'rows' => $rows,
            'delist' => $delist,
            'groupOptions' => $groupOptions,
            'withProfit' => $withProfit,
        ]);
    }

    /** Search, group, shop, reason and minimum value run on the cached list, so they never recompute anything. */
    private function filterCandidates(array $rows): array
    {
        $search = mb_strtolower(trim($this->search));
        $min = is_numeric($this->minValue) ? (float) $this->minValue : null;
        $shop = ctype_digit($this->shopFilter) ? (int) $this->shopFilter : null;

        $rows = array_values(array_filter($rows, fn (array $r) => ($search === '' || str_contains(mb_strtolower($r['name']), $search))
            && ($this->group === '' || ($r['group'] ?? '') === $this->group)
            && ($shop === null || $r['shop_id'] === $shop)
            && ($this->reason === '' || in_array($this->reason, $r['reasons'], true))
            && ($min === null || ($r['value'] !== null && $r['value'] >= $min))));

        usort($rows, match ($this->sort) {
            'name' => fn ($a, $b) => strcmp($a['name'], $b['name']),
            'cover' => fn ($a, $b) => [$b['cover'] ?? -1, $b['on_hand']] <=> [$a['cover'] ?? -1, $a['on_hand']],
            'days' => fn ($a, $b) => [$b['days_since'] ?? PHP_INT_MAX, $b['on_hand']] <=> [$a['days_since'] ?? PHP_INT_MAX, $a['on_hand']],
            default => fn ($a, $b) => [$b['value'] ?? -1, $b['on_hand']] <=> [$a['value'] ?? -1, $a['on_hand']],
        });

        return $rows;
    }
}
