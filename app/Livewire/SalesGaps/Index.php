<?php

namespace App\Livewire\SalesGaps;

use App\Services\SalesGaps;
use App\Support\ShopAccess;
use App\Support\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Sales growth > Sales gaps: where each shop sells less than the chain's own good shops (check, card share,
 * class A availability, peak hours). Nothing is calculated until Generate is pressed; see App\Services\SalesGaps.
 */
#[Layout('components.layouts.app', ['title' => 'Sales gaps'])]
class Index extends Component
{
    public string $dateFrom = '';

    public string $dateTo = '';

    /** @var array<int, int|string> Selected shop ids; empty = every shop the user may see. */
    public array $shopIds = [];

    public bool $generated = false;

    /** Changes on every Generate press so every press builds fresh data. */
    public string $runKey = '';

    /** The inputs the current report was built from. */
    public array $applied = [];

    /** Shop whose action panel is open (only ever looked up inside the report, so a foreign id shows nothing). */
    public ?int $selected = null;

    public function mount(): void
    {
        $days = (int) config('sales_gaps.default_days', 28);
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

        $min = (int) config('sales_gaps.min_days', 7);
        $max = (int) config('sales_gaps.max_days', 92);
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
        $this->selected = null;
        $this->generated = true;
    }

    public function selectShop(int $id): void
    {
        $this->selected = $this->selected === $id ? null : $id;
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

        if ($this->generated && $this->applied !== []) {
            $shopIds = $this->effectiveShopIds($this->applied['shops']);
            $key = 'sales-gaps.'.ShopAccess::salesKey('g', $this->applied['from'].'.'.$this->applied['to'].'.'.$this->runKey, $shopIds);
            $report = Cache::remember($key, (int) config('sales_gaps.cache_seconds', 600), fn () => app(SalesGaps::class)->build(Carbon::parse($this->applied['from']), Carbon::parse($this->applied['to']), $shopIds));
        }

        return view('livewire.sales-gaps.index', [
            'shops' => $this->availableShops(),
            'noShops' => ShopAccess::hasNone(),
            'report' => $report,
            'isAdmin' => (bool) auth()->user()?->isAdmin(),
        ]);
    }
}
