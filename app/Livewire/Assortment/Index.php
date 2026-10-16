<?php

namespace App\Livewire\Assortment;

use App\Services\AssortmentAnalysis;
use App\Support\LocalAdvisor;
use App\Support\ProfitAccess;
use App\Support\ShopAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Assortment analysis: ABC x XYZ matrix, A items out of stock, dead and slow stock, each with a plain conclusion.
 * Question it answers: "which items carry the business, which can be dropped, and where is cash or sales being lost?".
 * Default period: the last 12 full weeks ending yesterday (see AssortmentAnalysis for why).
 */
#[Layout('components.layouts.app', ['title' => 'Assortment analysis'])]
class Index extends Component
{
    private const MAX_DAYS = 92;

    private const MIN_DAYS = 7;

    private const CACHE_TTL = 600;

    public string $dateFrom = '';

    public string $dateTo = '';

    /** @var array<int, int|string> Selected shop ids; empty = every shop the user may see. */
    public array $shopIds = [];

    public bool $generated = false;

    /** The inputs the current report was built from. */
    public array $applied = [];

    public function mount(): void
    {
        $this->dateTo = now()->subDay()->toDateString();
        $this->dateFrom = now()->subDays(84)->toDateString();
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
        $this->generated = true;
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

        if ($this->generated && $this->applied !== []) {
            $shopIds = $this->effectiveShopIds($this->applied['shops']);
            $withProfit = ProfitAccess::allowed();
            // Cache key: scope + sales version + profit variant (cost figures never share a cache entry with the others).
            $key = 'assortment.'.ShopAccess::salesKey('a', $this->applied['from'].'.'.$this->applied['to'].'.'.ProfitAccess::profitKey($withProfit), $shopIds);
            $report = Cache::remember($key, self::CACHE_TTL, fn () => app(AssortmentAnalysis::class)->build(Carbon::parse($this->applied['from']), Carbon::parse($this->applied['to']), $shopIds, $withProfit));
            $notes = LocalAdvisor::notes('assortment_analysis', ['report' => $report]);
        }

        return view('livewire.assortment.index', [
            'shops' => $this->availableShops(),
            'noShops' => ShopAccess::hasNone(),
            'report' => $report,
            'notes' => $notes,
        ]);
    }
}
