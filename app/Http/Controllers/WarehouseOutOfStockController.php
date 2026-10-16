<?php

namespace App\Http\Controllers;

use App\Services\WarehouseOutOfStock;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** CSV download and print view of the main-warehouse out-of-stock list (admin only, same filters as the page). */
class WarehouseOutOfStockController extends Controller
{
    public function csv(Request $request, WarehouseOutOfStock $service): StreamedResponse
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $data = $service->data();
        $rows = $service->apply($data['rows'], WarehouseOutOfStock::filters($request->query()));
        $header = ['sku', 'name', 'group', 'abc', 'reason', 'last_qty', 'last_in_stock_date', 'days_out', 'chain_speed_per_day', 'shops_needing', 'need_qty', 'open_orders', 'min', 'max'];

        return response()->streamDownload(function () use ($rows, $header) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $header);
            foreach ($rows as $r) {
                fputcsv($out, [
                    self::text($r['sku']), self::text($r['name']), self::text($r['group']), $r['abc'], $r['reason'], $r['last_qty'],
                    $r['in_stock_date'], $r['days_out'], $r['speed'], $r['shops'], $r['need'], $r['open_orders'], $r['min'], $r['max'],
                ]);
            }
            fclose($out);
        }, 'warehouse-out-of-stock-'.($data['ref'] ?? 'none').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function print(Request $request, WarehouseOutOfStock $service)
    {
        abort_unless($request->user()?->isAdmin(), 403);

        $data = $service->data();
        $rows = $service->apply($data['rows'], WarehouseOutOfStock::filters($request->query()));
        $byGroup = [];
        foreach ($rows as $r) {
            $byGroup[$r['group'] ?? __('No group')][] = $r;
        }
        ksort($byGroup);

        return view('warehouse.out-of-stock-print', ['data' => $data, 'byGroup' => $byGroup, 'count' => count($rows)]);
    }

    /** Spreadsheet formula injection guard for text cells coming from 1C. */
    private static function text(?string $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
