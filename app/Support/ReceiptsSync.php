<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * State of the 1C receipts history migration, stored as JSON under the `settings` key "receipts_sync":
 *  - last_receipt_at  the receipt time (shop-local time as sent by 1C, 'Y-m-d H:i:s') up to which 1C has delivered;
 *                     GLOBAL, one value for all shops. 1C asks for it and sends everything newer; an admin can edit it
 *                     (set it back to re-import a period, empty = start from the beginning)
 *  - last_batch       {at, accepted, rejected} of the newest batch
 *  - rejected         the newest rejected receipts (pos, session, number, reason), newest first, at most 50
 */
class ReceiptsSync
{
    public const KEY = 'receipts_sync';

    public const BATCH_SIZE = 100;

    public const KEEP_REJECTED = 50;

    /** @return array<string, mixed> */
    public static function state(): array
    {
        $raw = DB::table('settings')->where('key', self::KEY)->value('value');
        $data = is_string($raw) ? json_decode($raw, true) : null;
        $data = is_array($data) ? $data : [];

        return [
            'last_receipt_at' => is_string($data['last_receipt_at'] ?? null) ? $data['last_receipt_at'] : null,
            'last_batch' => is_array($data['last_batch'] ?? null) ? $data['last_batch'] : null,
            'rejected' => is_array($data['rejected'] ?? null) ? array_values($data['rejected']) : [],
        ];
    }

    public static function lastReceiptAt(): ?Carbon
    {
        $value = self::state()['last_receipt_at'];

        return $value === null ? null : Carbon::parse($value);
    }

    /** Admin edit: null clears the cursor (1C starts from the beginning). */
    public static function setLastReceiptAt(?Carbon $at): void
    {
        self::write(['last_receipt_at' => $at?->format('Y-m-d H:i:s')]);
    }

    /**
     * After a batch: the cursor moves forward to the newest ACCEPTED receipt time (never back), and the batch summary and
     * the newest rejected receipts are kept for the settings page.
     *
     * @param  array<int, array<string, mixed>>  $rejected
     */
    public static function recordBatch(?Carbon $newest, int $accepted, array $rejected): void
    {
        $state = self::state();
        $current = $state['last_receipt_at'];
        $next = $current;
        if ($newest !== null && ($current === null || $newest->gt(Carbon::parse($current)))) {
            $next = $newest->format('Y-m-d H:i:s');
        }

        self::write([
            'last_receipt_at' => $next,
            'last_batch' => ['at' => now()->format('Y-m-d H:i:s'), 'accepted' => $accepted, 'rejected' => count($rejected)],
            'rejected' => array_slice([...$rejected, ...$state['rejected']], 0, self::KEEP_REJECTED),
        ]);
    }

    /** @param  array<string, mixed>  $changes */
    private static function write(array $changes): void
    {
        $data = array_merge(self::state(), $changes);

        DB::table('settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => json_encode($data), 'updated_at' => now(), 'created_at' => now()]
        );
    }
}
