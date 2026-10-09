<?php

namespace App\Livewire\ReceiptAnalytics;

use App\Models\Receipt;
use App\Services\ReceiptAnalytics;
use App\Support\ShopAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Receipt analytics'])]
class Index extends Component
{
    /** Longest range one report may cover (keeps the pair/percentile queries cheap). */
    private const MAX_DAYS = 92;

    private const CACHE_TTL = 600;

    public string $dateFrom = '';

    public string $dateTo = '';

    /** @var array<int, int|string> Selected shop ids; empty = every shop the user may see. */
    public array $shopIds = [];

    public bool $generated = false;

    /** Receipt shown in the modal (opened from a link in the report). */
    #[Locked]
    public ?int $receiptId = null;

    /** The inputs the current report was built from (the form can change without regenerating). */
    public array $applied = [];

    public function mount(): void
    {
        $this->dateTo = now()->toDateString();
        $this->dateFrom = now()->subDays(6)->toDateString();
    }

    public function generate(): void
    {
        $this->validate([
            'dateFrom' => ['required', 'date_format:Y-m-d'],
            'dateTo' => ['required', 'date_format:Y-m-d', 'after_or_equal:dateFrom'],
            'shopIds' => ['array'],
            'shopIds.*' => ['integer'],
        ]);

        if (Carbon::parse($this->dateFrom)->diffInDays(Carbon::parse($this->dateTo)) >= self::MAX_DAYS) {
            $this->addError('dateTo', __('The period can not be longer than :days days.', ['days' => self::MAX_DAYS]));

            return;
        }

        $this->applied = ['from' => $this->dateFrom, 'to' => $this->dateTo, 'shops' => array_map('intval', $this->shopIds)];
        $this->generated = true;
    }

    public function openReceipt(int $id): void
    {
        $shopId = Receipt::query()->whereKey($id)->value('shop_id');
        abort_if($shopId === null, 404);
        ShopAccess::authorize((int) $shopId); // never trust a client id: other shops' receipts are a 404

        $this->receiptId = $id;
    }

    public function closeReceipt(): void
    {
        $this->receiptId = null;
    }

    /** Shops the user may pick from. */
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
        $shops = $this->availableShops();
        $report = [];

        if ($this->generated && $this->applied !== []) {
            $shopIds = $this->effectiveShopIds($this->applied['shops']);
            $from = Carbon::parse($this->applied['from']);
            $to = Carbon::parse($this->applied['to']);

            $key = 'receipt-analytics.'.ShopAccess::salesKey('r', $this->applied['from'].'.'.$this->applied['to'], $shopIds);
            $report = Cache::remember($key, self::CACHE_TTL, fn () => app(ReceiptAnalytics::class)->build($from, $to, $shopIds));
        }

        $receipt = $this->receiptId === null ? null
            : Receipt::query()->with(['pos', 'shop', 'items.item', 'payments'])->find($this->receiptId);
        if ($receipt && ! ShopAccess::allows((int) $receipt->shop_id)) {
            $receipt = null; // scope changed since it was opened
        }

        return view('livewire.receipt-analytics.index', [
            'shops' => $shops,
            'noShops' => ShopAccess::hasNone(),
            'report' => $report,
            'receipt' => $receipt,
        ]);
    }
}
