<?php

namespace App\Livewire\Shops;

use App\Models\Shop;
use App\Support\ShopAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Shop'])]
class Show extends Component
{
    public Shop $shop;

    public function mount(Shop $shop): void
    {
        ShopAccess::authorize($shop->id);
        $this->shop = $shop;
    }

    public string $areaM2 = '';

    public string $mapUrl = '';

    public bool $editing = false;

    public function edit(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        $this->areaM2 = $this->shop->area_m2 !== null ? rtrim(rtrim((string) $this->shop->area_m2, '0'), '.') : '';
        $this->mapUrl = (string) $this->shop->map_url;
        $this->resetErrorBag();
        $this->editing = true;
    }

    /** Area and map link are typed in here only; 1C never sends or overwrites them. */
    public function save(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
        ShopAccess::authorize($this->shop->id);

        $this->areaM2 = str_replace([' ', ','], ['', '.'], trim($this->areaM2));
        $this->mapUrl = trim($this->mapUrl);

        $data = $this->validate([
            'areaM2' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'mapUrl' => ['nullable', 'url:https', 'max:500', 'regex:'.Shop::MAP_HOST_PATTERN],
        ], [
            'mapUrl.regex' => __('Use a Yandex Maps link (https://yandex...).'),
        ], [
            'areaM2' => __('Area'),
            'mapUrl' => __('Yandex Maps link'),
        ]);

        $this->shop->forceFill([
            'area_m2' => $data['areaM2'] === '' || $data['areaM2'] === null ? null : $data['areaM2'],
            'map_url' => $data['mapUrl'] === '' ? null : $data['mapUrl'],
        ])->save();

        $this->editing = false;
        session()->flash('status', __('Saved.'));
    }

    /** Livewire updates skip the route; re-check in case the assignment changed meanwhile. */
    public function hydrate(): void
    {
        ShopAccess::authorize($this->shop->id);
    }

    public function render()
    {
        $stocks = $this->shop->currentStocks()->with('item')->orderByDesc('qty')->get();
        // Explicit zero: a pair the shop's latest 1C sync did not report is shown as "Not reported", not as stock.
        $latest = \App\Models\Stock::latestDates([(int) $this->shop->id]);
        foreach ($stocks as $s) {
            $s->reported = \App\Models\Stock::isReported((string) $s->stock_date, $latest[(int) $s->shop_id] ?? null);
        }

        return view('livewire.shops.show', ['stocks' => $stocks]);
    }
}
