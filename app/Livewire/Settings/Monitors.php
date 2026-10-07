<?php

namespace App\Livewire\Settings;

use App\Enums\MonitorType;
use App\Models\Monitor;
use App\Models\Shop;
use App\Support\PublicUrl;
use App\Support\Warehouse;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('components.layouts.app', ['title' => 'Monitors'])]
class Monitors extends Component
{
    public bool $showModal = false;

    public ?int $monitorId = null;

    public string $name = '';

    public string $type = 'executive';

    /** @var array<int, int|string> Shops shown by the monitor; empty = all shops. */
    public array $shopIds = [];

    public bool $enabled = true;

    public bool $showProfit = false;

    /**
     * Runs on every request, including Livewire updates, which skip the route
     * middleware - so a demoted admin can't keep using an already-open page.
     */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function create(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $monitor = Monitor::query()->findOrFail($id);

        $this->resetForm();
        $this->monitorId = $monitor->id;
        $this->name = $monitor->name;
        $this->type = $monitor->type->value;
        $this->shopIds = $monitor->shops()->pluck('shops.id')->map(fn ($id) => (string) $id)->all();
        $this->enabled = $monitor->enabled;
        $this->showProfit = $monitor->show_profit;
        $this->showModal = true;
    }

    public function save(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(MonitorType::class)],
            'shopIds' => ['array'],
            'shopIds.*' => ['integer', 'exists:shops,id'],
            'enabled' => ['boolean'],
            'showProfit' => ['boolean'],
        ]);

        $attributes = [
            'name' => $data['name'],
            'type' => $data['type'],
            'enabled' => $data['enabled'],
            'show_profit' => $data['showProfit'],
        ];

        $monitor = $this->monitorId
            ? tap(Monitor::query()->findOrFail($this->monitorId))->update($attributes)
            : Monitor::query()->create($attributes);

        $monitor->shops()->sync(array_values(array_unique(array_map('intval', $data['shopIds'] ?? []))));

        $this->closeModal();
    }

    public function delete(int $id): void
    {
        Monitor::query()->findOrFail($id)->delete();
    }

    /** First or new UUID link; the previous one stops working immediately. */
    public function generateLink(int $id): void
    {
        Monitor::query()->findOrFail($id)->regenerateToken();
    }

    public function selectAllShops(): void
    {
        $this->shopIds = Shop::query()->pluck('id')->map(fn ($id) => (string) $id)->all();
    }

    public function clearShops(): void
    {
        $this->shopIds = [];
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    protected function validationAttributes(): array
    {
        return [
            'name' => __('Name'),
            'type' => __('Type'),
            'shopIds' => __('Shops'),
        ];
    }

    private function resetForm(): void
    {
        $this->reset(['monitorId', 'name', 'type', 'shopIds', 'enabled', 'showProfit']);
        $this->resetErrorBag();
    }

    public function render()
    {
        return view('livewire.settings.monitors', [
            'monitors' => Monitor::query()->with('shops:id,name')->orderBy('name')->orderBy('id')->get(),
            'shops' => Shop::query()->orderBy('name')->get(['id', 'name']),
            'types' => MonitorType::cases(),
            'warehouse' => Warehouse::shop(),
            // Built from APP_URL, not from the host of the current request, so the
            // links are the same address the TV should use (e.g. behind a proxy).
            'appUrl' => PublicUrl::base(),
            'appUrlLooksLocal' => PublicUrl::looksLocal(),
        ]);
    }
}
