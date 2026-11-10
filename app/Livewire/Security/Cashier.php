<?php

namespace App\Livewire\Security;

use App\Services\SecurityWarnings;
use App\Support\ShopAccess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Drill-down of one cashier (of a shop) or one till: refund / cancel / discount / off-hours numbers against the rest of
 * the chain for the last security.detail_days days, by day, and the receipts worth a look. It covers one person only, so
 * it opens at once (no Generate); the figures are cached 10 minutes.
 */
#[Layout('components.layouts.app', ['title' => 'Security details'])]
class Cashier extends Component
{
    use WithPagination;

    #[Locked]
    public int $shopId = 0;

    /** null = a till view; '' = receipts without a cashier name. */
    #[Locked]
    public ?string $cashier = null;

    #[Locked]
    public ?int $posId = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function mount(): void
    {
        $this->shopId = (int) request()->query('shop');
        $this->posId = request()->query('pos') !== null ? (int) request()->query('pos') : null;
        $this->cashier = $this->posId === null && request()->query->has('cashier') ? (string) request()->query('cashier') : null;

        abort_if($this->shopId <= 0 || ($this->posId === null && $this->cashier === null), 404);
        ShopAccess::authorize($this->shopId);
        abort_unless(DB::table('shops')->where('id', $this->shopId)->exists(), 404);
        abort_if($this->posId !== null && ! DB::table('pos')->where('id', $this->posId)->where('shop_id', $this->shopId)->exists(), 404);
    }

    /** Shops of the chain baseline: everything the viewer may see. */
    private function scope(): array
    {
        return ShopAccess::restrict(DB::table('shops'), 'id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function render()
    {
        $service = app(SecurityWarnings::class);
        $scope = $this->scope();

        $detail = Cache::remember(
            ShopAccess::salesKey('security.detail', md5($this->shopId.'|'.$this->cashier.'|'.$this->posId.'|'.now()->toDateString()), $scope),
            (int) config('security.cache_seconds'),
            fn () => $service->detail($scope, $this->shopId, $this->cashier, $this->posId)
        );

        return view('livewire.security.cashier', [
            'detail' => $detail,
            'receipts' => $service->flaggedReceipts($scope, $this->shopId, $this->cashier, $this->posId)->paginate((int) config('security.detail_per_page')),
            'deepPct' => (float) config('security.deep_discount_pct'),
            'minReceipts' => (int) config('security.min_receipts'),
            'subject' => $this->posId !== null ? $detail['pos'] : (filled($this->cashier) ? $this->cashier : __('Unknown cashier')),
        ]);
    }
}
