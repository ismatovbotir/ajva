<?php

namespace App\Livewire\Monitoring;

use App\Services\DataHealth as DataHealthService;
use App\Support\ShopAccess;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Monitoring > Data health: is the receipt feed (which every order quantity depends on) complete and clean? Per shop the
 * newest receipt, the last 24 h against the usual day, the tills and the feed lag; for the chain the rejected receipts, late
 * receipts, flagged receipts, the history cursor and the stock sync next to the sales feed. Live (no Generate button), cached
 * 60 s with the sales version. Admin only. Metric dictionary: App\Services\DataHealth.
 */
#[Layout('components.layouts.app', ['title' => 'Data health'])]
class DataHealth extends Component
{
    /** Runs on every request, including Livewire updates (they skip route middleware). */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function render()
    {
        $health = app(DataHealthService::class)->cached(ShopAccess::ids());

        return view('livewire.monitoring.data-health', ['h' => $health]);
    }
}
