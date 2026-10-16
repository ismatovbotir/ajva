<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReceiptRequest;
use App\Jobs\ProcessReceiptIngestion;
use App\Models\Receipt;
use App\Support\ReceiptsSync;
use App\Support\ShopAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * 1C history migration of receipts (local network only, like the items sync):
 *  GET  /api/receipts/last   -> the global "last receipt" date and time 1C continues after (null = from the beginning)
 *  POST /api/receipts/batch  -> a bare JSON array of up to 100 receipts in the SAME structure as POST /api/receipts
 * Every receipt is validated and stored on its own (identity pos + session + number, so a resend updates): a receipt
 * that fails (for example an item id that is not in the catalog) is REJECTED and reported back, the others are kept.
 * The cursor then moves to the newest accepted receipt time, and the sales caches are invalidated ONCE per batch.
 */
class ReceiptSyncController extends Controller
{
    public function last(): JsonResponse
    {
        $at = ReceiptsSync::lastReceiptAt();

        return response()->json([
            'last_receipt_at' => $at?->format('Y-m-d H:i:s'),
            // The same format 1C sends in openDate / openTime.
            'date' => $at?->format('d.m.y'),
            'time' => $at?->format('H:i:s'),
            'batch_size' => ReceiptsSync::BATCH_SIZE,
        ]);
    }

    public function batch(Request $request): JsonResponse
    {
        $receipts = $request->json()->all();

        if (! is_array($receipts) || ! array_is_list($receipts)) {
            return response()->json(['message' => 'The body must be a JSON array of receipts.'], 422);
        }
        if (count($receipts) > ReceiptsSync::BATCH_SIZE) {
            return response()->json(['message' => 'At most '.ReceiptsSync::BATCH_SIZE.' receipts per request.'], 422);
        }

        $rules = (new ReceiptRequest)->rules();
        $accepted = 0;
        $newest = null;
        $rejected = [];

        foreach ($receipts as $index => $receipt) {
            $ident = is_array($receipt)
                ? ['index' => $index, 'pos' => $receipt['pos'] ?? null, 'session' => $receipt['session'] ?? null, 'number' => $receipt['number'] ?? null]
                : ['index' => $index, 'pos' => null, 'session' => null, 'number' => null];

            if (! is_array($receipt)) {
                $rejected[] = $ident + ['reason' => 'Not an object.'];

                continue;
            }

            $validator = Validator::make($receipt, $rules);
            if ($validator->fails()) {
                $rejected[] = $ident + ['reason' => implode(' ', array_slice($validator->errors()->all(), 0, 3))];

                continue;
            }

            $data = $validator->validated();
            try {
                $at = ProcessReceiptIngestion::receiptTime($data);
                (new ProcessReceiptIngestion($data, (int) $data['pos'], false, Receipt::SOURCE_HISTORY))->handle();
            } catch (Throwable $e) {
                Log::warning('Receipt batch: receipt rejected', ['pos' => $data['pos'], 'number' => $data['number'], 'error' => $e->getMessage()]);
                $rejected[] = $ident + ['reason' => 'Could not be stored: '.mb_substr($e->getMessage(), 0, 160)];

                continue;
            }

            $accepted++;
            $newest = $newest === null || $at->gt($newest) ? $at : $newest;
        }

        if ($accepted > 0) {
            ShopAccess::bumpSalesVersion();
        }
        ReceiptsSync::recordBatch($newest, $accepted, $rejected);

        return response()->json([
            'accepted' => $accepted,
            'rejected' => $rejected,
            'last_receipt_at' => ReceiptsSync::lastReceiptAt()?->format('Y-m-d H:i:s'),
        ]);
    }
}
