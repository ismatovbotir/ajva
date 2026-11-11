<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Log of the 1C items sync. Successes are NOT rows: they only touch the single row of the day in item_sync_days
 * (last_success_at = the update time, batches / items counters). Every error is one row in item_sync_errors
 * (newest inventory.item_sync_keep_errors kept). Logging never breaks the request or the job: failures only go to
 * the Laravel log.
 */
class ItemsSyncLog
{
    public const STAGE_VALIDATION = 'validation';

    public const STAGE_JOB = 'job';

    public const STAGE_SKIPPED = 'skipped';

    /** A batch was processed: the day row gets the update time and the counters. */
    public static function success(int $items): void
    {
        try {
            self::bumpDay(['batches' => 1, 'items' => $items], now()->toDateTimeString());
        } catch (Throwable $e) {
            Log::warning('Items sync log (success) failed: '.$e->getMessage());
        }
    }

    /** One error event (stage: validation | job | skipped). No payload beyond the first failing item id / mark. */
    public static function error(string $stage, string $message, ?int $itemId = null, ?int $shopId = null, ?int $batchSize = null, ?string $excerpt = null, ?string $ip = null): void
    {
        try {
            DB::table('item_sync_errors')->insert([
                'occurred_at' => now()->toDateTimeString(),
                'stage' => mb_substr($stage, 0, 20),
                'message' => mb_substr($message, 0, 500),
                'item_id' => $itemId,
                'shop_id' => $shopId,
                'batch_size' => $batchSize,
                'excerpt' => $excerpt === null ? null : mb_substr($excerpt, 0, 200),
                'ip' => $ip === null ? null : mb_substr($ip, 0, 45),
            ]);

            self::bumpDay(['error_count' => 1], null);
            self::prune();
        } catch (Throwable $e) {
            Log::warning('Items sync log (error) failed: '.$e->getMessage());
        }
    }

    /** Keeps the newest N errors. */
    public static function prune(): void
    {
        $keep = max(1, (int) config('inventory.item_sync_keep_errors', 1000));

        $cut = DB::table('item_sync_errors')->orderByDesc('id')->skip($keep - 1)->take(1)->value('id');

        if ($cut !== null) {
            DB::table('item_sync_errors')->where('id', '<', $cut)->delete();
        }
    }

    /** Atomic increment of today's row, created on first use (a racing insert falls back to the increment). */
    private static function bumpDay(array $counters, ?string $successAt): void
    {
        $date = now()->toDateString();
        $extra = $successAt === null ? [] : ['last_success_at' => $successAt];

        $bump = function () use ($date, $counters, $extra): int {
            $q = DB::table('item_sync_days')->where('date', $date);
            $first = array_key_first($counters);
            $rest = $extra;
            foreach ($counters as $col => $by) {
                if ($col !== $first) {
                    $rest[$col] = DB::raw($col.' + '.(int) $by);
                }
            }

            return $q->increment($first, (int) $counters[$first], $rest);
        };

        if ($bump() > 0) {
            return;
        }

        try {
            DB::table('item_sync_days')->insert(array_merge(
                ['date' => $date, 'batches' => 0, 'items' => 0, 'error_count' => 0],
                $counters,
                $extra,
            ));
        } catch (QueryException) {
            $bump();
        }
    }
}
