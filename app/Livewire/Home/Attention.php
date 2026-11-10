<?php

namespace App\Livewire\Home;

use App\Services\AttentionItems;
use App\Support\ShopAccess;
use Livewire\Component;

/** "Needs attention" panel of the Home page (rows from App\Services\AttentionItems, polled every minute). */
class Attention extends Component
{
    public function boot(): void
    {
        abort_unless(in_array(auth()->user()?->role?->value, ['admin', 'operator'], true), 403);
    }

    public function render()
    {
        $report = app(AttentionItems::class)->report(auth()->user());

        return view('livewire.home.attention', [
            'rows' => $report['rows'],
            'checkedAt' => $report['checked_at'],
            // An operator without shops sees the "no shops assigned" hint of the page instead of a false "All clear".
            'noShops' => ShopAccess::hasNone(),
        ]);
    }
}
