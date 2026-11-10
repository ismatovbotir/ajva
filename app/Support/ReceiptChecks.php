<?php

namespace App\Support;

use App\Jobs\ProcessReceiptIngestion;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Data checks on an incoming receipt (see config/receipts.php).
 *
 *  - rejections()  the CLEARLY IMPOSSIBLE (422): qty = 0 on a non-storno position, |qty| above receipts.max_qty, a receipt time
 *                  more than receipts.future_days days ahead. Only with receipts.strict_validation (default true).
 *  - flags()       everything merely SUSPICIOUS is accepted and flagged (receipts.dq_flags): neg_qty, bad_total, sum_lt_total,
 *                  qty_gt_max, old_date. Only with receipts.flagging (default true).
 * The unknown-item-id rule stays in ReceiptRequest (exists:items,id).
 */
class ReceiptChecks
{
    public const FLAGS = ['neg_qty', 'bad_total', 'sum_lt_total', 'qty_gt_max', 'old_date'];

    /** Flags already counted by their own Security rows (data_total / data_discount): not repeated in the flagged-receipts row. */
    public const COVERED_ELSEWHERE = ['bad_total', 'sum_lt_total'];

    /** Flag code => label (English source, translate with __()). */
    public static function flagLabels(): array
    {
        return [
            'neg_qty' => 'Negative quantity on a sale line',
            'bad_total' => 'Sale total of zero or less',
            'sum_lt_total' => 'Sum before discount below the total',
            'qty_gt_max' => 'Quantity far above the item max',
            'old_date' => 'Receipt date older than 5 years',
        ];
    }

    /**
     * @param  array<string, mixed>  $data  the payload (validated or not: every access is guarded)
     * @return array<int, array{code: string, key: string, message: string}>
     */
    public static function rejections(array $data, ?Carbon $now = null): array
    {
        if (! config('receipts.strict_validation', true)) {
            return [];
        }

        $now ??= now();
        $out = [];
        $max = (float) config('receipts.max_qty', 100000);

        $positions = is_array($data['positions'] ?? null) ? $data['positions'] : [];
        foreach ($positions as $i => $position) {
            if (! is_array($position) || ! is_numeric($position['qty'] ?? null)) {
                continue;
            }
            $qty = (float) $position['qty'];
            $storno = filter_var($position['storno'] ?? false, FILTER_VALIDATE_BOOLEAN);
            if ($qty == 0.0 && ! $storno) {
                $out[] = ['code' => 'qty_zero', 'key' => "positions.$i.qty", 'message' => 'The quantity of a position cannot be 0.'];
            } elseif ($max > 0 && abs($qty) > $max) {
                $out[] = ['code' => 'qty_absurd', 'key' => "positions.$i.qty", 'message' => 'The quantity of a position is above the allowed maximum ('.$max.').'];
            }
        }

        $at = self::time($data);
        if ($at !== null && $at->gt($now->copy()->addDays((int) config('receipts.future_days', 1)))) {
            $out[] = ['code' => 'future_date', 'key' => 'openDate', 'message' => 'The receipt date is more than '.(int) config('receipts.future_days', 1).' day in the future.'];
        }

        return $out;
    }

    /** Adds the impossible-value errors to a validator (a FormRequest or the history batch validator). */
    public static function attach(Validator $validator, array $data): void
    {
        $validator->after(function (Validator $v) use ($data) {
            foreach (self::rejections($data) as $r) {
                $v->errors()->add($r['key'], $r['message']);
            }
        });
    }

    /**
     * Flag codes of an accepted receipt, in a fixed order; [] = nothing suspicious.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    public static function flags(array $data, bool $active, bool $sell, int $shopId, ?Carbon $now = null): array
    {
        if (! config('receipts.flagging', true)) {
            return [];
        }

        $now ??= now();
        $found = [];
        $total = (float) ($data['total'] ?? 0);

        if ($active && $sell && $total <= 0) {
            $found['bad_total'] = true;
        }
        if (isset($data['sum']) && is_numeric($data['sum']) && (float) $data['sum'] < $total - 0.005) {
            $found['sum_lt_total'] = true;
        }

        $positions = is_array($data['positions'] ?? null) ? $data['positions'] : [];
        $multiple = (float) config('receipts.item_max_multiple', 10);
        $lines = [];
        foreach ($positions as $position) {
            if (! is_array($position) || ! is_numeric($position['qty'] ?? null)) {
                continue;
            }
            $qty = (float) $position['qty'];
            if ((bool) ($position['storno'] ?? false)) {
                continue;
            }
            if ($sell && $qty < 0) {
                $found['neg_qty'] = true;
            }
            if ($qty > 0 && isset($position['item']['id'])) {
                $lines[] = [(int) $position['item']['id'], $qty];
            }
        }

        if ($lines !== [] && $multiple > 0) {
            $limits = DB::table('item_order_rules')
                ->where('shop_id', $shopId)->where('max', '>', 0)
                ->whereIn('item_id', array_values(array_unique(array_column($lines, 0))))
                ->pluck('max', 'item_id');
            foreach ($lines as [$itemId, $qty]) {
                if (isset($limits[$itemId]) && $qty > $multiple * (float) $limits[$itemId]) {
                    $found['qty_gt_max'] = true;
                    break;
                }
            }
        }

        $at = self::time($data);
        if ($at !== null && $at->lt($now->copy()->subYears((int) config('receipts.old_years', 5)))) {
            $found['old_date'] = true;
        }

        return array_values(array_filter(self::FLAGS, fn (string $f) => isset($found[$f])));
    }

    /** SQL for "arrived more than ? hours after the receipt time" (bind the hours); needs the receipts table unaliased. */
    public static function lateSql(): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? '(julianday(receipts.ingested_at) - julianday(receipts.created_at)) * 24 > ?'
            : 'TIMESTAMPDIFF(HOUR, receipts.created_at, receipts.ingested_at) > ?';
    }

    private static function time(array $data): ?Carbon
    {
        if (! is_string($data['openDate'] ?? null) || ! is_string($data['openTime'] ?? null)) {
            return null;
        }

        try {
            return ProcessReceiptIngestion::receiptTime($data);
        } catch (Throwable) {
            return null;
        }
    }
}
