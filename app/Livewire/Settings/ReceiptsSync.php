<?php

namespace App\Livewire\Settings;

use App\Support\PublicUrl;
use App\Support\ReceiptsSync as Sync;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Settings > Receipts migration (1C), admin only: the global "last receipt" date and time 1C continues after (editable:
 * set it back to re-import a period, clear it to start from the beginning), the two local-network endpoints 1C calls and
 * the result of the newest batch with the receipts that were rejected.
 */
#[Layout('components.layouts.app', ['title' => 'Receipts migration (1C)'])]
class ReceiptsSync extends Component
{
    /** datetime-local value (Y-m-dTH:i:s); empty = from the beginning. */
    public string $lastReceiptAt = '';

    public bool $saved = false;

    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function mount(): void
    {
        $this->load();
    }

    private function load(): void
    {
        $at = Sync::lastReceiptAt();
        $this->lastReceiptAt = $at === null ? '' : $at->format('Y-m-d\TH:i:s');
    }

    public function save(): void
    {
        $this->validate(['lastReceiptAt' => ['nullable', 'date_format:Y-m-d\TH:i:s,Y-m-d\TH:i']]);

        Sync::setLastReceiptAt($this->lastReceiptAt === '' ? null : Carbon::parse($this->lastReceiptAt));
        $this->load();
        $this->saved = true;
    }

    /** Start from the beginning: 1C will send everything again (resending is safe, a receipt is identified by pos + session + number). */
    public function clear(): void
    {
        Sync::setLastReceiptAt(null);
        $this->load();
        $this->saved = true;
    }

    public function updatedLastReceiptAt(): void
    {
        $this->saved = false;
    }

    protected function validationAttributes(): array
    {
        return ['lastReceiptAt' => __('Last receipt date and time')];
    }

    public function render()
    {
        $state = Sync::state();

        return view('livewire.settings.receipts-sync', [
            'state' => $state,
            'lastUrl' => PublicUrl::to('api/receipts/last'),
            'batchUrl' => PublicUrl::to('api/receipts/batch'),
            'batchSize' => Sync::BATCH_SIZE,
        ]);
    }
}
