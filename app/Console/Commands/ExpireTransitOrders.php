<?php

namespace App\Console\Commands;

use App\Enums\OrderStatus;
use App\Livewire\Ordering\Index as OrderingIndex;
use App\Models\Order;
use App\Models\OrderEvent;
use App\Support\InTransit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Flags confirmed orders nobody confirmed the receipt of as `expired` (close_reason) once they are older than
 * inventory.in_transit_max_days + inventory.in_transit_grace_hours. LEAST INVASIVE: the status stays `confirmed` and
 * no received quantity is invented; the order can still be received late (the personal link and the order page keep
 * working) and it shows in the "Not confirmed orders" report as expired. The forecast already ignores such orders
 * through the in-transit window (App\Support\InTransit), the flag only makes it visible and auditable.
 */
class ExpireTransitOrders extends Command
{
    protected $signature = 'orders:expire-transit';

    protected $description = 'Flag confirmed orders that were never received as expired (they stop counting as in transit)';

    public function handle(): int
    {
        $limit = InTransit::cutoff()->subHours(max(0, (int) config('inventory.in_transit_grace_hours', 12)));

        $ids = Order::query()
            ->where('status', OrderStatus::Confirmed->value)
            ->whereNull('received_at')
            ->whereNull('close_reason')
            ->where(DB::raw('COALESCE(confirmed_at, created_at)'), '<', $limit->toDateTimeString())
            ->pluck('id');

        foreach ($ids as $id) {
            // Guarded update: a receipt confirmed meanwhile is never overwritten.
            $changed = Order::query()->whereKey($id)->whereNull('close_reason')->whereNull('received_at')
                ->where('status', OrderStatus::Confirmed->value)
                ->update(['close_reason' => Order::CLOSE_EXPIRED]);
            if ($changed > 0) {
                OrderEvent::record($id, null, OrderEvent::CHANNEL_SYSTEM, OrderEvent::EXPIRED);
            }
        }

        if ($ids->isNotEmpty()) {
            OrderingIndex::bumpOrdersVersion();
        }
        $this->line($ids->count().' order(s) expired.');

        return self::SUCCESS;
    }
}
