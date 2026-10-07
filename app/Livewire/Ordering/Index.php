<?php

namespace App\Livewire\Ordering;

use App\Services\OrderingReport;
use App\Support\ShopAccess;
use App\Support\Warehouse;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Ordering report: a separate list of items to order for each shop, from its
 * min/max rules and the stock corrected by net sales (see OrderingReport).
 * No filters - just a Refresh button that recalculates.
 */
#[Layout('components.layouts.app', ['title' => 'Ordering'])]
class Index extends Component
{
    /** The report is shared per shop scope for a few minutes; Refresh always recalculates. */
    private const CACHE_TTL = 600;

    /** Changes on every Refresh press; part of the cache key. */
    public string $runKey = 'initial';

    public ?int $shopId = null;

    public function refresh(): void
    {
        $this->runKey = bin2hex(random_bytes(4));

        // Build now (the "Calculating" popup covers the wait) so the re-render only reads the cache.
        $this->report();
    }

    public function selectShop(int $shopId): void
    {
        // Never trust the client: a shop outside the user's scope is a 404.
        ShopAccess::authorize($shopId);

        $this->shopId = $shopId;
    }

    public function render()
    {
        $noShops = ShopAccess::hasNone();
        $report = $noShops ? ['generated_at' => null, 'total_items' => 0, 'shops' => []] : $this->report();
        $shops = collect($report['shops']);

        // A forged/stale shop id falls back to the first shop that has something to order.
        if ($shops->isNotEmpty() && ! $shops->contains('id', $this->shopId)) {
            $this->shopId = ($shops->firstWhere('count', '>', 0) ?? $shops->first())['id'];
        }

        return view('livewire.ordering.index', [
            'noShops' => $noShops,
            'report' => $report,
            'shops' => $shops,
            'active' => $shops->firstWhere('id', $this->shopId),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function report(): array
    {
        $ids = ShopAccess::ids();

        return Cache::remember(
            'ordering.report.'.ShopAccess::scopeKey($ids).'.w'.(Warehouse::shopId() ?? 0).'.'.$this->runKey,
            self::CACHE_TTL,
            fn () => app(OrderingReport::class)->build($ids, $this->excludedShopIds()),
        );
    }

    /**
     * Shops that are never replenished: the main warehouse chosen in Settings > Warehouse.
     *
     * @return array<int, int>
     */
    private function excludedShopIds(): array
    {
        $warehouse = Warehouse::shopId();

        return $warehouse === null ? [] : [$warehouse];
    }
}
