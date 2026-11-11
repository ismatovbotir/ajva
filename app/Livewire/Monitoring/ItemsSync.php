<?php

namespace App\Livewire\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Monitoring > Items sync log: the last 30 days of the 1C items sync (one row per day) and the latest errors. Admin only. */
#[Layout('components.layouts.app', ['title' => 'Items sync log'])]
class ItemsSync extends Component
{
    /** Runs on every request, including Livewire updates (they skip route middleware). */
    public function boot(): void
    {
        abort_unless(auth()->user()?->isAdmin(), 403);
    }

    public function render()
    {
        $days = DB::table('item_sync_days')->orderByDesc('date')->limit(30)->get()->map(function ($d) {
            $d->last_success_at = $d->last_success_at ? Carbon::parse($d->last_success_at) : null;

            return $d;
        });

        $errors = DB::table('item_sync_errors')->orderByDesc('id')->limit(100)->get()->map(function ($e) {
            $e->occurred_at = Carbon::parse($e->occurred_at);

            return $e;
        });

        return view('livewire.monitoring.items-sync', ['days' => $days, 'errors' => $errors]);
    }
}
