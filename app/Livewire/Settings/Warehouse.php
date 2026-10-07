<?php

namespace App\Livewire\Settings;

use App\Models\Shop;
use App\Support\Warehouse as WarehouseSetting;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Warehouse'])]
class Warehouse extends Component
{
    /** Shop id of the main warehouse; empty string = not set. */
    public string $shopId = '';

    public bool $saved = false;

    /**
     * Runs on every request, including Livewire updates, which skip the route
     * middleware - so a demoted admin can't keep using an already-open page.
     */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function mount(): void
    {
        $this->shopId = (string) (WarehouseSetting::shopId() ?? '');
    }

    public function save(): void
    {
        $this->validate(['shopId' => ['nullable', Rule::exists('shops', 'id')]]);

        WarehouseSetting::setShopId($this->shopId === '' ? null : (int) $this->shopId);
        $this->saved = true;
    }

    public function updatedShopId(): void
    {
        $this->saved = false;
    }

    protected function validationAttributes(): array
    {
        return ['shopId' => __('Main warehouse')];
    }

    public function render()
    {
        return view('livewire.settings.warehouse', [
            'shops' => Shop::query()->orderBy('name')->get(['id', 'name']),
            'configured' => WarehouseSetting::isConfigured(),
        ]);
    }
}
