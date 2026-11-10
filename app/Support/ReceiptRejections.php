<?php

namespace App\Support;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Makes rejected receipts countable. Every receipt that is refused (422 on POST /api/receipts, or rejected inside a history
 * batch) leaves one row per distinct reason in `receipt_rejections` (the newest receipts.keep_rejections = 500 are kept) and
 * increments a per-day, per-reason counter in `receipt_rejection_counts`. Recording never breaks the request: any failure is
 * only logged. Reason codes: unknown_item, qty_zero, qty_absurd, future_date, invalid (any other validation error), store_error.
 */
class ReceiptRejections
{
    public const REASONS = ['unknown_item', 'qty_zero', 'qty_absurd', 'future_date', 'invalid', 'store_error'];

    /** Reason code => label (English source, translate with __()). */
    public static function labels(): array
    {
        return [
            'unknown_item' => 'Item not in the catalog',
            'qty_zero' => 'Quantity 0',
            'qty_absurd' => 'Quantity above the maximum',
            'future_date' => 'Date in the future',
            'invalid' => 'Other validation error',
            'store_error' => 'Could not be stored',
        ];
    }

    /**
     * The reasons behind a failed validator: [{reason, item_id, detail}].
     *
     * @param  array<string, mixed>  $input
     * @return array<int, array{reason: string, item_id: int|null, detail: string}>
     */
    public static function reasons(Validator $validator, array $input): array
    {
        $out = [];
        foreach (ReceiptChecks::rejections($input) as $r) {
            $out[$r['code'].'|'.$r['key']] = ['reason' => $r['code'], 'item_id' => null, 'detail' => $r['message']];
        }

        foreach ($validator->failed() as $key => $rules) {
            if (preg_match('/^positions\.\d+\.item\.id$/', (string) $key) && isset($rules['Exists'])) {
                $id = data_get($input, $key);
                $out['unknown_item|'.(is_scalar($id) ? $id : '?')] = [
                    'reason' => 'unknown_item',
                    'item_id' => is_numeric($id) ? (int) $id : null,
                    'detail' => 'Item id '.(is_scalar($id) ? $id : '?').' is not in the catalog.',
                ];

                continue;
            }
            $out['invalid|'.$key] = ['reason' => 'invalid', 'item_id' => null, 'detail' => $key.': '.array_key_first($rules)];
        }

        return array_slice(array_values($out), 0, 8);
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array<int, array{reason: string, item_id?: int|null, detail?: string}>  $reasons
     */
    public static function record(string $source, array $input, array $reasons): void
    {
        if ($reasons === []) {
            return;
        }

        try {
            $shop = $input['shop'] ?? null;
            $pos = $input['pos'] ?? null;
            $session = $input['session'] ?? null;
            $number = $input['number'] ?? null;
            $now = now();
            $lastId = 0;
            $seen = [];

            foreach ($reasons as $r) {
                $lastId = (int) DB::table('receipt_rejections')->insertGetId([
                    'source' => $source,
                    'shop_id' => is_numeric($shop) ? (int) $shop : null,
                    'pos_id' => is_numeric($pos) ? (int) $pos : null,
                    'session' => is_numeric($session) ? (int) $session : null,
                    'number' => is_scalar($number) ? mb_substr((string) $number, 0, 255) : null,
                    'reason' => $r['reason'],
                    'item_id' => $r['item_id'] ?? null,
                    'detail' => isset($r['detail']) ? mb_substr((string) $r['detail'], 0, 255) : null,
                    'created_at' => $now,
                ]);
                $seen[$r['reason']] = true;
            }

            foreach (array_keys($seen) as $reason) {
                self::count($now->toDateString(), $reason);
            }

            // Ids only grow: everything older than the newest N rows goes.
            DB::table('receipt_rejections')->where('id', '<=', $lastId - max(1, (int) config('receipts.keep_rejections', 500)))->delete();
        } catch (Throwable $e) {
            Log::warning('Receipt rejection could not be recorded', ['error' => $e->getMessage()]);
        }
    }

    private static function count(string $date, string $reason): void
    {
        $where = ['stat_date' => $date, 'reason' => $reason];
        if (DB::table('receipt_rejection_counts')->where($where)->increment('total') > 0) {
            return;
        }
        try {
            DB::table('receipt_rejection_counts')->insert($where + ['total' => 1]);
        } catch (QueryException) {
            DB::table('receipt_rejection_counts')->where($where)->increment('total');
        }
    }
}
