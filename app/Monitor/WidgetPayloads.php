<?php

namespace App\Monitor;

use App\Services\AssortmentAnalysis;
use App\Services\SalesMetrics;
use App\Support\MonitorScope;
use App\Support\ReceiptRejections;
use Illuminate\Support\Str;

/**
 * Turns shared datasets into one widget payload each (plain arrays the Blade diagram components draw).
 * One method per catalog key (camelCase of the key). A payload is {state, status, ...shape fields}:
 *
 *  single    value, compare?, spark?, gauge?, note?
 *  series    labels, lines[{name, tone, values}], cut, format
 *  by_shop / by_item   columns[], items[{label, value, text, cells[], status, heat[]}], kinds[]
 *  parts     parts[{label, value, text, color}]
 *  events    events[{time, shop, till, total, payment, tag}]
 *
 * state: ok | empty ("No data yet"). Profit/cost numbers are only reached from the profit dataset, which
 * Datasets never builds without profit permission.
 */
class WidgetPayloads
{
    public function __construct(private readonly Datasets $data, private readonly MonitorScope $scope) {}

    /**
     * @param  array<string, mixed>  $widget  a saved layout entry
     * @return array<string, mixed>
     */
    public function build(Widget $def, array $widget): array
    {
        $method = Str::camel($def->key);
        $payload = $this->{$method}($widget['options'] ?? [], $widget['diagram']);

        return $payload + ['state' => 'ok', 'status' => 'none'];
    }

    // ---------------------------------------------------------------- formatting

    private function money(float|int|null $n): string
    {
        return number_format((float) $n, 0, '.', ' ');
    }

    private function dec(float|int|null $n, int $d = 1): string
    {
        return number_format((float) $n, $d, '.', ' ');
    }

    private function qty(float $n): string
    {
        return rtrim(rtrim(number_format($n, 3, '.', ' '), '0'), '.') ?: '0';
    }

    private function pctText(?float $pct): string
    {
        return $pct === null ? __('n/a') : ($pct >= 0 ? '▲ +' : '▼ ').number_format($pct, 1).'%';
    }

    private function empty(array $extra = []): array
    {
        return ['state' => 'empty'] + $extra;
    }

    private function board(): array
    {
        return $this->data->get('receipts')['board'];
    }

    /**
     * The comparison block of a figure: values of the three windows (same time of day), both deltas, their colours.
     *
     * @param  callable(float):string  $fmt
     * @return array<string, mixed>
     */
    private function compare(float $now, float $prev, float $week, int $prevBase, int $weekBase, callable $fmt, ?bool $weekData = null): array
    {
        $c = Comparison::make($now, $prev, $week, $prevBase, $weekBase, $weekData ?? $this->data->get('receipts')['week_day_has_data']);
        $asOf = $this->board()['asOf'];

        return $c + [
            'prev_text' => $fmt($prev),
            'lw_text' => $fmt($week),
            'a_text' => $this->pctText($c['a']),
            'b_text' => $this->pctText($c['b']),
            'a_status' => Comparison::deltaStatus($c['a']),
            'b_status' => Comparison::deltaStatus($c['b']),
            'prev_label' => __('Yesterday by :time', ['time' => $asOf]),
            'lw_label' => __('Last week by :time', ['time' => $asOf]),
        ];
    }

    /** A count-based single figure from the receipts board (sales, receipts, average check, basket, net). */
    private function receiptsFigure(string $field, callable $fmt, ?callable $value = null): array
    {
        $b = $this->board();
        if ($b['today']['all'] === 0 && $b['yesterday']['all'] === 0 && $b['week']['all'] === 0) {
            return $this->empty();
        }

        $v = $value ?? fn (array $t) => (float) $t[$field];
        $cmp = $this->compare($v($b['today']), $v($b['yesterday']), $v($b['week']), $b['yesterday']['count'], $b['week']['count'], $fmt);

        return ['value' => $fmt($cmp['now']), 'compare' => $cmp, 'status' => $cmp['status']];
    }

    /** @return array<int, float> daily series of the 8-day trend for sparklines */
    private function spark(string $field, ?callable $per = null): array
    {
        $days = $this->data->get('trend')['days'];

        return array_map(fn (array $d) => $per ? $per($d) : (float) $d[$field], $days);
    }

    // ---------------------------------------------------------------- sales

    private function salesRevenue(array $o, string $diagram): array
    {
        $p = $this->receiptsFigure('sum', fn ($n) => $this->money($n));

        return $diagram === 'sparkline' ? $p + ['spark' => $this->spark('revenue')] : $p;
    }

    private function salesNet(array $o, string $diagram): array
    {
        return $this->receiptsFigure('sum', fn ($n) => $this->money($n), fn (array $t) => $t['sum'] - $t['refund_sum']);
    }

    private function receiptCount(array $o, string $diagram): array
    {
        $p = $this->receiptsFigure('count', fn ($n) => $this->money($n));

        return $diagram === 'sparkline' ? $p + ['spark' => $this->spark('count')] : $p;
    }

    private function avgCheck(array $o, string $diagram): array
    {
        $p = $this->receiptsFigure('avg', fn ($n) => $this->money($n));

        return $diagram === 'sparkline'
            ? $p + ['spark' => $this->spark('', fn (array $d) => $d['count'] > 0 ? $d['revenue'] / $d['count'] : 0.0)]
            : $p;
    }

    private function basketSize(array $o, string $diagram): array
    {
        return $this->receiptsFigure('basket', fn ($n) => $this->dec($n, 1));
    }

    private function hourlySales(array $o, string $diagram): array
    {
        $metric = ($o['metric'] ?? 'sum') === 'count' ? 'count' : 'sum';
        $set = $this->data->get('hourly');
        $hours = $set['series'][$metric];
        $current = $set['current_hour'];

        $today = array_map(fn ($h) => (float) $h['total'], $hours);
        $prev = array_map(fn ($h) => (float) $h['y_total'], $hours);
        $week = array_map(fn ($h) => (float) $h['w_total'], $hours);

        $active = array_values(array_filter(range(0, 23), fn ($i) => $today[$i] > 0 || $prev[$i] > 0 || $week[$i] > 0));
        if ($active === []) {
            return $this->empty();
        }

        $from = min($active);
        $to = min(23, max(max($active), $current));
        $to = max($to, min(23, $from + 5));
        $range = range($from, $to);

        return [
            'format' => $metric,
            'labels' => array_map(fn ($i) => sprintf('%02d', $i), $range),
            'cut' => array_search($current, $range, true) === false ? null : array_search($current, $range, true),
            'lines' => [
                ['name' => __('Today'), 'tone' => 'now', 'values' => array_map(fn ($i) => $i > $current ? null : $today[$i], $range)],
                ['name' => __('Yesterday'), 'tone' => 'prev', 'values' => array_map(fn ($i) => $prev[$i], $range)],
                ['name' => __('Last week'), 'tone' => 'week', 'values' => array_map(fn ($i) => $week[$i], $range)],
            ],
            'legend_note' => __('Yesterday and last week are whole days.'),
        ];
    }

    private function shopLeaderboard(array $o, string $diagram): array
    {
        $b = $this->board();
        $shops = $b['shops'];
        if ($b['today']['all'] === 0 || $shops === []) {
            return $this->empty();
        }

        $maxSum = max(1.0, ...array_map(fn ($s) => (float) $s['today']['sum'], $shops));
        $maxCount = max(1, ...array_map(fn ($s) => $s['today']['count'], $shops));
        $maxAvg = max(1.0, ...array_map(fn ($s) => (float) $s['today']['avg'], $shops));
        $heat = fn (?float $pct) => $pct === null ? null : max(-1.0, min(1.0, $pct / 30));
        $weekData = $this->data->get('receipts')['week_day_has_data'];

        $items = [];
        foreach ($shops as $s) {
            $c = Comparison::make($s['today']['sum'], $s['yesterday']['sum'], $s['week']['sum'], $s['yesterday']['count'], $s['week']['count'], $weekData);
            $items[] = [
                'label' => $s['name'],
                'value' => (float) $s['today']['sum'],
                'text' => $this->money($s['today']['sum']),
                'cells' => [
                    $this->money($s['today']['sum']), (string) $s['today']['count'], $this->money($s['today']['avg']),
                    $this->pctText($c['a']), $this->pctText($c['b']),
                ],
                'heat' => [
                    $s['today']['sum'] / $maxSum, $s['today']['count'] / $maxCount, $s['today']['avg'] / $maxAvg,
                    $heat($c['a']), $heat($c['b']),
                ],
                'tones' => [null, null, null, Comparison::deltaStatus($c['a']), Comparison::deltaStatus($c['b'])],
                'status' => $c['status'],
            ];
        }

        return [
            'columns' => [__('Sales total'), __('Receipts'), __('Average check'), __('vs yesterday'), __('vs last week')],
            'kinds' => ['seq', 'seq', 'seq', 'div', 'div'],
            'items' => $items,
            'status' => Comparison::make($b['today']['sum'], $b['yesterday']['sum'], $b['week']['sum'], $b['yesterday']['count'], $b['week']['count'], $weekData)['status'],
        ];
    }

    private function topItems(array $o, string $diagram): array
    {
        $set = $this->data->get('top_items');
        if ($set['items'] === []) {
            return $this->empty();
        }

        $n = max(1, min(WidgetCatalog::TOP_N_MAX, (int) ($o['top_n'] ?? WidgetCatalog::TOP_N_DEFAULT)));
        $items = [];
        foreach (array_slice($set['items'], 0, $n) as $i) {
            $a = Comparison::pct($i['qty'], $i['prev_qty'], $set['prev_base']);
            $b = Comparison::pct($i['qty'], $i['week_qty'], $set['week_base']);
            $items[] = [
                'label' => $i['name'],
                'value' => $i['qty'],
                'text' => $this->qty($i['qty']),
                'cells' => [$this->qty($i['qty']), $this->money($i['sum']), $this->pctText($a), $this->pctText($b)],
                'tones' => [null, null, Comparison::deltaStatus($a), Comparison::deltaStatus($b)],
                'status' => Comparison::status($a, $b),
            ];
        }

        return [
            'columns' => [__('Quantity'), __('Sales total'), __('vs yesterday'), __('vs last week')],
            'items' => $items,
            'footer' => __('Yesterday by :time', ['time' => $set['as_of']]).' / '.__('Last week by :time', ['time' => $set['as_of']]),
        ];
    }

    private function paymentMix(array $o, string $diagram): array
    {
        $payments = $this->board()['payments'];
        if ($payments === []) {
            return $this->empty();
        }

        $parts = [];
        foreach (array_slice($payments, 0, 6) as $p) {
            $parts[] = ['label' => $p['name'], 'value' => (float) $p['percent'], 'text' => $this->dec($p['percent'], 1).'%', 'color' => $p['color'], 'detail' => $p['sum']];
        }
        if (count($payments) > 6) {
            $rest = array_sum(array_map(fn ($p) => $p['percent'], array_slice($payments, 6)));
            $parts[] = ['label' => __('Other'), 'value' => (float) $rest, 'text' => $this->dec($rest, 1).'%', 'color' => SalesMetrics::COLOR_OTHER, 'detail' => ''];
        }

        return ['parts' => $parts];
    }

    private function trend8d(array $o, string $diagram): array
    {
        $metric = ($o['metric'] ?? 'sum') === 'count' ? 'count' : 'sum';
        $days = $this->data->get('trend')['days'];
        $values = array_map(fn ($d) => (float) ($metric === 'count' ? $d['count'] : $d['revenue']), $days);
        if (array_sum($values) <= 0) {
            return $this->empty();
        }

        return [
            'format' => $metric,
            'labels' => array_column($days, 'label'),
            'cut' => count($days) - 1,
            'lines' => [['name' => $metric === 'count' ? __('Receipts') : __('Sales total'), 'tone' => 'now', 'values' => $values]],
            'highlight' => [0, count($days) - 1],
        ];
    }

    // ---------------------------------------------------------------- receipts

    private function rateFigure(string $field, array $gauge): array
    {
        $b = $this->board();
        $t = $b['today'];
        if ($t['all'] === 0) {
            return $this->empty();
        }

        $value = (float) $t[$field];
        $prev = (float) $b['yesterday'][$field];
        $week = (float) $b['week'][$field];
        $asOf = $b['asOf'];

        // Rates compare in percentage points; colour comes from the rate itself (2 % amber, 5 % red).
        $pts = fn (float $base, int $receipts) => $receipts >= Comparison::MIN_BASE ? $value - $base : null;
        $a = $pts($prev, $b['yesterday']['all']);
        $bb = $pts($week, $b['week']['all']);
        $status = Comparison::rateStatus($value, $t['all']);

        return [
            'value' => $this->dec($value, 1).'%',
            'status' => $status,
            'gauge' => ['value' => $value, 'min' => 0, 'max' => $gauge['max'], 'marks' => $gauge['marks']],
            'compare' => [
                'now' => $value, 'prev_day' => $prev, 'same_weekday_lw' => $week,
                'prev_text' => $this->dec($prev, 1).'%', 'lw_text' => $this->dec($week, 1).'%',
                'a_text' => $a === null ? __('n/a') : ($a >= 0 ? '+' : '−').$this->dec(abs($a), 1).' '.__('pts'),
                'b_text' => $bb === null ? __('n/a') : ($bb >= 0 ? '+' : '−').$this->dec(abs($bb), 1).' '.__('pts'),
                // For a "bad" rate going up is the bad direction.
                'a_status' => $a === null ? 'none' : ($a > 0 ? 'warn' : 'ok'),
                'b_status' => $bb === null ? 'none' : ($bb > 0 ? 'warn' : 'ok'),
                'status' => $status,
                'incomplete' => ! $this->data->get('receipts')['week_day_has_data'],
                'prev_label' => __('Yesterday by :time', ['time' => $asOf]),
                'lw_label' => __('Last week by :time', ['time' => $asOf]),
            ],
        ];
    }

    private function refundRate(array $o, string $diagram): array
    {
        return $this->rateFigure('refund_amount_percent', ['max' => 10, 'marks' => [2, 5]]);
    }

    private function cancelledRate(array $o, string $diagram): array
    {
        return $this->rateFigure('cancelled_percent', ['max' => 10, 'marks' => [2, 5]]);
    }

    private function discountRate(array $o, string $diagram): array
    {
        $p = $this->rateFigure('discount_percent', ['max' => 30, 'marks' => []]);
        // A discount level is information, not an alarm: no red/amber.
        if (($p['state'] ?? null) === 'empty') {
            return $p;
        }
        $p['status'] = 'none';
        $p['compare']['status'] = 'none';

        return $p;
    }

    private function tillsActive(array $o, string $diagram): array
    {
        $tills = $this->board()['tills'];
        if ($tills['total'] === 0) {
            return $this->empty();
        }

        $pct = $tills['active'] / $tills['total'] * 100;

        return [
            'value' => $tills['active'].' / '.$tills['total'],
            'note' => __('Sale in the last :minutes minutes', ['minutes' => 30]),
            'gauge' => ['value' => $pct, 'min' => 0, 'max' => 100, 'marks' => []],
        ];
    }

    private function receiptTicker(array $o, string $diagram): array
    {
        $latest = $this->board()['latest'];
        if ($latest === []) {
            return $this->empty();
        }

        return ['events' => array_map(fn (array $r) => [
            'time' => $r['time'],
            'shop' => $r['shop'],
            'till' => $r['pos'],
            'total' => $this->money($r['total']),
            'payment' => $r['payment'],
            'tag' => $r['cancelled'] ? 'cancelled' : ($r['refund'] ? 'refund' : null),
        ], $latest)];
    }

    // ---------------------------------------------------------------- profit (dataset exists only with profit access)

    /** @return array{profit: float, covered: float, revenue: float} */
    private function profitTotals(array $rows): array
    {
        return [
            'profit' => array_sum(array_column($rows, 'profit')),
            'covered' => array_sum(array_column($rows, 'covered')),
            'revenue' => array_sum(array_column($rows, 'revenue')),
        ];
    }

    private function grossProfit(array $o, string $diagram): array
    {
        $set = $this->data->get('profit');
        $now = $this->profitTotals($set['today']);
        if ($now['revenue'] <= 0 && $this->profitTotals($set['prev'])['revenue'] <= 0) {
            return $this->empty();
        }

        $cmp = $this->compare($now['profit'], $this->profitTotals($set['prev'])['profit'], $this->profitTotals($set['week'])['profit'],
            $this->board()['yesterday']['count'], $this->board()['week']['count'], fn ($n) => $this->money($n));
        $uncovered = $now['revenue'] > 0 ? round(($now['revenue'] - $now['covered']) / $now['revenue'] * 100, 1) : 0.0;

        // No cost price on any of today's revenue: the profit is unknown, not zero.
        if ($now['revenue'] > 0 && $now['covered'] <= 0) {
            return ['value' => '—', 'status' => 'none', 'note' => __(':percent% of revenue has no cost price.', ['percent' => 100])];
        }

        return [
            'value' => $this->money($now['profit']),
            'compare' => $cmp,
            'status' => $cmp['status'],
            'note' => $uncovered > 0 ? __(':percent% of revenue has no cost price.', ['percent' => $uncovered]) : null,
        ];
    }

    private function marginPct(array $o, string $diagram): array
    {
        $set = $this->data->get('profit');
        $margin = fn (array $rows) => ($t = $this->profitTotals($rows))['covered'] > 0 ? $t['profit'] / $t['covered'] * 100 : null;
        $now = $margin($set['today']);
        if ($now === null) {
            return $this->empty();
        }
        $prev = $margin($set['prev']);
        $week = $margin($set['week']);
        $asOf = $set['as_of'];
        $status = Comparison::marginStatus($now, $prev, $week);
        $pts = fn (?float $base) => $base === null ? __('n/a') : ($now - $base >= 0 ? '+' : '−').$this->dec(abs($now - $base), 1).' '.__('pts');
        $dir = fn (?float $base) => $base === null ? 'none' : ($base - $now >= 5 ? 'bad' : ($base - $now >= 2 ? 'warn' : 'ok'));

        return [
            'value' => $this->dec($now, 1).'%',
            'status' => $status,
            'gauge' => ['value' => max(0, $now), 'min' => 0, 'max' => 50, 'marks' => []],
            'compare' => [
                'now' => $now, 'prev_day' => $prev, 'same_weekday_lw' => $week,
                'prev_text' => $prev === null ? __('n/a') : $this->dec($prev, 1).'%',
                'lw_text' => $week === null ? __('n/a') : $this->dec($week, 1).'%',
                'a_text' => $pts($prev), 'b_text' => $pts($week),
                'a_status' => $dir($prev), 'b_status' => $dir($week),
                'status' => $status,
                'incomplete' => ! $this->data->get('receipts')['week_day_has_data'],
                'prev_label' => __('Yesterday by :time', ['time' => $asOf]),
                'lw_label' => __('Last week by :time', ['time' => $asOf]),
            ],
        ];
    }

    private function profitByShop(array $o, string $diagram): array
    {
        $set = $this->data->get('profit');
        $items = [];
        foreach ($set['shops'] as $id => $name) {
            $t = $set['today'][$id] ?? null;
            if ($t === null || $t['revenue'] <= 0) {
                continue;
            }
            $margin = $t['covered'] > 0 ? $t['profit'] / $t['covered'] * 100 : null;
            $prev = $set['prev'][$id] ?? null;
            $prevMargin = $prev && $prev['covered'] > 0 ? $prev['profit'] / $prev['covered'] * 100 : null;
            $items[] = [
                'label' => (string) $name,
                'value' => $t['profit'],
                'text' => $t['covered'] > 0 ? $this->money($t['profit']) : '—',
                'cells' => [$t['covered'] > 0 ? $this->money($t['profit']) : '—', $margin === null ? '—' : $this->dec($margin, 1).'%'],
                'status' => Comparison::marginStatus($margin, $prevMargin, null),
            ];
        }
        if ($items === []) {
            return $this->empty();
        }
        usort($items, fn ($a, $b) => $b['value'] <=> $a['value']);

        return ['columns' => [__('Profit'), __('Margin')], 'items' => $items];
    }

    // ---------------------------------------------------------------- inventory

    private function plan(): array
    {
        return $this->data->get('inventory')['plan'];
    }

    private function inventoryFooter(): string
    {
        $date = $this->data->get('inventory')['stock_as_of'];

        return $date === null ? __('No stock data yet.') : __('Stock as of last sync: :date', ['date' => $date]);
    }

    private function stockoutCount(array $o, string $diagram): array
    {
        $inv = $this->data->get('inventory');
        if ($inv['ruled'] === 0) {
            return $this->empty(['footer' => $this->inventoryFooter()]);
        }

        $out = $inv['plan']['kpi']['out'];
        $status = $out === 0 ? 'ok' : ($out / $inv['ruled'] * 100 > 5 ? 'bad' : 'warn');

        return [
            'value' => (string) $out,
            'status' => $status,
            'note' => __('of :count items with an order rule', ['count' => $inv['ruled']])
                .(($inv['no_data'] ?? 0) > 0 ? ' · '.__(':count not reported by 1C', ['count' => $inv['no_data']]) : ''),
            'footer' => $this->inventoryFooter(),
        ];
    }

    private function lowCoverItems(array $o, string $diagram): array
    {
        $plan = $this->plan();
        $rows = [];
        foreach ($plan['shops'] as $shop) {
            foreach ($shop['rows'] as $r) {
                if (in_array($r['urgency'], ['out', 'critical', 'soon'], true)) {
                    $rows[] = $r + ['shop_name' => $shop['name']];
                }
            }
        }
        if ($rows === []) {
            return $this->empty(['footer' => $this->inventoryFooter()]);
        }

        usort($rows, fn ($a, $b) => [$a['level'] === 'out' ? 0 : 1, $a['cover'] ?? 0, $a['item']] <=> [$b['level'] === 'out' ? 0 : 1, $b['cover'] ?? 0, $b['item']]);
        $n = max(1, min(WidgetCatalog::TOP_N_MAX, (int) ($o['top_n'] ?? WidgetCatalog::TOP_N_DEFAULT)));

        return [
            'columns' => [__('Shop'), __('Estimated stock'), __('Cover, days')],
            'items' => array_map(fn (array $r) => [
                'label' => $r['item'],
                'value' => (float) ($r['cover'] ?? 0),
                'text' => $r['level'] === 'out' ? __('Out of stock') : $this->dec($r['cover'], 1).' '.__('d'),
                'cells' => [$r['shop_name'], $this->qty((float) $r['estimated']), $r['level'] === 'out' ? '0' : $this->dec($r['cover'], 1)],
                'status' => ($r['level'] === 'out' || $r['urgency'] === 'critical' || ($r['cover'] !== null && $r['cover'] < 1)) ? 'bad' : 'warn',
            ], array_slice($rows, 0, $n)),
            'inverse' => true,
            'footer' => $this->inventoryFooter(),
        ];
    }

    private function replenishmentNeed(array $o, string $diagram): array
    {
        $items = [];
        foreach ($this->plan()['shops'] as $shop) {
            if ($shop['count'] === 0) {
                continue;
            }
            $units = array_sum(array_column($shop['rows'], 'need'));
            $items[] = [
                'label' => $shop['name'],
                'value' => (float) $units,
                'text' => $this->qty((float) $units),
                'cells' => [(string) $shop['count'], $this->qty((float) $units), (string) ($shop['counts']['out'] + $shop['counts']['critical'])],
                'status' => $shop['counts']['out'] > 0 ? 'bad' : ($shop['counts']['critical'] + $shop['counts']['soon'] > 0 ? 'warn' : 'ok'),
            ];
        }
        if ($items === []) {
            return $this->empty(['footer' => $this->inventoryFooter()]);
        }

        return [
            'columns' => [__('Lines'), __('Units'), __('Urgent')],
            'items' => $items,
            'footer' => $this->inventoryFooter(),
        ];
    }

    private function whShortage(array $o, string $diagram): array
    {
        $inv = $this->data->get('inventory');
        if (! $inv['plan']['warehouse_configured']) {
            return $this->empty(['footer' => __('The main warehouse is not set (Settings > Warehouse).')]);
        }

        $k = $inv['plan']['kpi'];

        return [
            'value' => (string) $k['short_items'],
            'status' => $k['starved'] > 0 ? 'bad' : ($k['short_items'] > 0 ? 'warn' : 'ok'),
            'note' => __(':count shop lines cannot be served in full', ['count' => $k['short_lines']]),
            'footer' => $this->inventoryFooter(),
        ];
    }

    private function whPickList(array $o, string $diagram): array
    {
        $plan = $this->plan();
        if ($plan['pick'] === []) {
            return $this->empty(['footer' => $this->inventoryFooter()]);
        }

        $n = max(1, min(WidgetCatalog::TOP_N_MAX, (int) ($o['top_n'] ?? WidgetCatalog::TOP_N_DEFAULT)));

        return [
            'columns' => [__('Send'), __('Need'), __('Short')],
            'items' => array_map(fn (array $i) => [
                'label' => $i['item'],
                'value' => (float) $i['send'],
                'text' => $this->qty((float) $i['send']),
                'cells' => [$this->qty((float) $i['send']), $this->qty((float) $i['need']), $i['short'] > 0 ? $this->qty((float) $i['short']) : '—'],
                'status' => $i['starved'] ? 'bad' : ($i['short'] > 0 ? 'warn' : 'ok'),
            ], array_slice($plan['pick'], 0, $n)),
            'footer' => $this->inventoryFooter(),
        ];
    }

    private function whCover(array $o, string $diagram): array
    {
        $plan = $this->plan();
        if (! $plan['warehouse_configured'] || $plan['pick'] === []) {
            return $this->empty(['footer' => $plan['warehouse_configured'] ? $this->inventoryFooter() : __('The main warehouse is not set (Settings > Warehouse).')]);
        }

        $need = array_sum(array_column($plan['pick'], 'need'));
        $send = array_sum(array_column($plan['pick'], 'send'));
        $pct = $need > 0 ? $send / $need * 100 : 100.0;

        return [
            'value' => $this->dec($pct, 0).'%',
            'status' => $pct < 70 ? 'bad' : ($pct < 95 ? 'warn' : 'ok'),
            'note' => __('of the needed units can be sent'),
            'gauge' => ['value' => $pct, 'min' => 0, 'max' => 100, 'marks' => [70, 95], 'inverse' => true],
            'footer' => $this->inventoryFooter(),
        ];
    }

    // ---------------------------------------------------------------- assortment

    private function abcMix(array $o, string $diagram): array
    {
        $set = $this->data->get('assortment');
        $abc = $set['abc'];
        if ($set['items'] === 0 || $abc === []) {
            return $this->empty();
        }

        $colors = ['A' => '#1baf7a', 'B' => '#eda100', 'C' => '#898781'];
        $parts = [];
        foreach (['A' => __('A: top sales'), 'B' => __('B: middle'), 'C' => __('C: tail')] as $class => $label) {
            $parts[] = ['label' => $label.' · '.$abc[$class]['items'].' '.__('items'), 'value' => (float) $abc[$class]['share'], 'text' => $this->dec($abc[$class]['share'], 1).'%', 'color' => $colors[$class], 'detail' => ''];
        }

        return ['parts' => $parts, 'footer' => __('Last :w weeks. Stock as of last sync: :date', ['w' => $set['weeks'], 'date' => $set['stock_as_of'] ?? '-'])];
    }

    private function abcXyzMatrix(array $o, string $diagram): array
    {
        $set = $this->data->get('assortment');
        if (! $set['xyz'] || $set['matrix'] === null || $set['items'] === 0) {
            return $this->empty(['footer' => __('XYZ needs at least :w full weeks of sales.', ['w' => AssortmentAnalysis::MIN_WEEKS_XYZ])]);
        }

        $max = 0.0;
        foreach ($set['matrix'] as $cols) {
            foreach ($cols as $cell) {
                $max = max($max, (float) $cell['share']);
            }
        }
        $items = [];
        foreach (['A' => __('A: top sales'), 'B' => __('B: middle'), 'C' => __('C: tail')] as $class => $label) {
            $cells = [];
            $heat = [];
            $sum = 0.0;
            foreach (['X', 'Y', 'Z'] as $x) {
                $c = $set['matrix'][$class][$x];
                $cells[] = $c['items'].' · '.$this->dec($c['share'], 0).'%';
                $heat[] = $max > 0 ? $c['share'] / $max : 0.0;
                $sum += $c['share'];
            }
            $items[] = ['label' => $label, 'value' => $sum, 'text' => $this->dec($sum, 0).'%', 'cells' => $cells, 'heat' => $heat, 'status' => $class === 'C' && $set['matrix']['C']['Z']['items'] > 0 ? 'warn' : 'none'];
        }

        return [
            'columns' => [__('X: steady demand'), __('Y: some swings'), __('Z: erratic')],
            'kinds' => ['seq', 'seq', 'seq'],
            'items' => $items,
            'footer' => __('Items · share of sales. Last :w weeks.', ['w' => $set['weeks']]),
        ];
    }

    private function aItemsOut(array $o, string $diagram): array
    {
        $set = $this->data->get('assortment');
        if ($set['items'] === 0) {
            return $this->empty();
        }

        return [
            'value' => (string) $set['a_at_risk'],
            'status' => $set['a_at_risk'] > 0 ? 'bad' : 'ok',
            'note' => __('in at least one shop now'),
            'footer' => __('Stock as of last sync: :date', ['date' => $set['stock_as_of'] ?? '-']),
        ];
    }

    private function unsoldItems(array $o, string $diagram): array
    {
        $set = $this->data->get('unsold');
        $k = $set['kpi'];
        if ($k['stocked_items'] === 0) {
            return $this->empty();
        }

        $status = $k['unsold_items'] === 0 ? 'ok' : ($k['unsold_share'] >= 20 ? 'warn' : 'none');
        $p = [
            'value' => $this->money($k['unsold_items']),
            'status' => $status,
            'note' => __(':p % of the items in stock · :u units', ['p' => $this->dec($k['unsold_share'], 1), 'u' => $this->qty((float) $k['unsold_units'])]),
            'footer' => __('No sale in :d days. Stock as of last sync: :date', ['d' => $set['days'], 'date' => $k['stock_as_of'] ?? '-']),
        ];

        return $diagram === 'gauge' ? $p + ['gauge' => ['value' => (float) $k['unsold_share'], 'min' => 0, 'max' => 50, 'marks' => [20]]] : $p;
    }

    private function unsoldByGroup(array $o, string $diagram): array
    {
        $set = $this->data->get('unsold');
        if ($set['groups'] === []) {
            return $this->empty();
        }

        $n = max(1, min(WidgetCatalog::TOP_N_MAX, (int) ($o['top_n'] ?? WidgetCatalog::TOP_N_DEFAULT)));
        $items = [];
        foreach (array_slice($set['groups'], 0, $n) as $g) {
            $items[] = [
                'label' => $g['group'] !== '' ? $g['group'] : __('No group'),
                'value' => (float) $g['unsold'],
                'text' => (string) $g['unsold'],
                'cells' => [(string) $g['unsold'], (string) $g['stocked'], $this->dec($g['share'], 0).'%'],
                'status' => $g['share'] >= 50 ? 'bad' : ($g['share'] >= 20 ? 'warn' : 'none'),
            ];
        }

        return [
            'columns' => [__('Unsold items'), __('In stock'), __('Share')],
            'items' => $items,
            'footer' => __('No sale in :d days. Stock as of last sync: :date', ['d' => $set['days'], 'date' => $set['kpi']['stock_as_of'] ?? '-']),
        ];
    }

    private function whOutOfStock(array $o, string $diagram): array
    {
        $d = $this->data->get('wh_oos');
        if ($d['state'] === 'unconfigured') {
            return $this->empty(['footer' => __('The main warehouse is not set (Settings > Warehouse).')]);
        }
        if ($d['state'] === 'empty') {
            return $this->empty(['footer' => __('1C has not sent any stock for the main warehouse.')]);
        }
        if ($d['state'] === 'not_synced') {
            return ['value' => '—', 'status' => 'bad', 'note' => __('Warehouse not synced since :date', ['date' => $d['ref']]), 'footer' => __('Other shops have newer stock (:date), so no item is listed as out of stock.', ['date' => $d['chain']])];
        }

        $k = $d['kpi'];
        if ($d['partial'] || $k === null) {
            return ['value' => '—', 'status' => 'warn', 'note' => __('The latest warehouse sync looks incomplete. The list below is only a warning, check the sync in 1C.'), 'footer' => __('Warehouse stock as of :date.', ['date' => $d['ref']])];
        }

        return [
            'value' => (string) $k['out'],
            'status' => $k['a'] > 0 ? 'bad' : ($k['out'] > 0 ? 'warn' : 'ok'),
            'note' => __('A-class: :a', ['a' => $k['a']])
                .(($k['not_reported'] ?? 0) > 0 ? ' · '.__(':count not reported by 1C', ['count' => $k['not_reported']]) : ''),
            'footer' => __('Warehouse stock as of :date.', ['date' => $d['ref']]),
        ];
    }

    private function customerShare(array $o, string $diagram): array
    {
        $set = $this->data->get('customers');
        if ($set['all'] === 0) {
            return $this->empty();
        }

        $pct = $set['with'] / $set['all'] * 100;
        $p = ['value' => $this->dec($pct, 1).'%', 'status' => 'none', 'note' => __(':n of :m sale receipts today', ['n' => $set['with'], 'm' => $set['all']])];

        return $diagram === 'gauge' ? $p + ['gauge' => ['value' => $pct, 'min' => 0, 'max' => 100, 'marks' => []]] : $p;
    }

    // ---------------------------------------------------------------- security (admin viewers only; see Datasets::securityWeek / securityToday)

    private const SEVERITY_STATUS = ['high' => 'bad', 'medium' => 'warn', 'low' => 'none', 'none' => 'ok'];

    private const SEVERITY_RANK = ['high' => 0, 'medium' => 1, 'low' => 2];

    private function severityLabel(string $severity): string
    {
        return ['high' => __('High'), 'medium' => __('Medium'), 'low' => __('Low')][$severity] ?? '—';
    }

    private function notEnough(string $why, array $extra = []): array
    {
        return ['value' => '—', 'status' => 'none', 'note' => __('Not enough data: :why', ['why' => $why])] + $extra;
    }

    private function weekFooter(array $set): string
    {
        return __('Last 7 days until :time. A signal to review, not proof.', ['time' => $set['as_of']]);
    }

    private function todayFooter(array $set): string
    {
        return __('Today until :time. A signal to review, not proof.', ['time' => $set['as_of']]);
    }

    private function securityWarningsCount(array $o, string $diagram): array
    {
        $set = $this->data->get('security_week');
        $min = (int) config('security.min_receipts');
        if ($set['receipts'] < $min) {
            return $this->notEnough(__('fewer than :n receipts in the last 7 days.', ['n' => $min]), ['footer' => $this->weekFooter($set)]);
        }

        $k = $set['kpi'];

        return [
            'value' => (string) $k['total'],
            'status' => $k['high'] > 0 ? 'bad' : ($k['medium'] > 0 ? 'warn' : ($k['low'] > 0 ? 'none' : 'ok')),
            'note' => __('High :h · Medium :m · Low :l', ['h' => $k['high'], 'm' => $k['medium'], 'l' => $k['low']]),
            'footer' => $this->weekFooter($set),
        ];
    }

    /**
     * Flagged cashiers of one rate check as a ranking: share, the rest of the chain, how many times higher, the sample, the level.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function cashierRanking(array $set, array $rows, array $o, string $allClear, string $what): array
    {
        if ($set['judgeable'] === 0) {
            return $this->empty(['footer' => __('Not enough data: a cashier needs :n receipts and the rest of the chain :m.', ['n' => (int) config('security.min_receipts'), 'm' => (int) config('security.min_baseline_receipts')])]);
        }

        $columns = [__('Share'), __('Rest of the chain'), __('Times higher'), __('Receipts'), __('Level')];
        usort($rows, fn (array $a, array $b) => [self::SEVERITY_RANK[$a['severity']], -$a['value']] <=> [self::SEVERITY_RANK[$b['severity']], -$b['value']]);
        $n = max(1, min(WidgetCatalog::TOP_N_MAX, (int) ($o['top_n'] ?? WidgetCatalog::TOP_N_DEFAULT)));

        $items = [];
        foreach (array_slice($rows, 0, $n) as $r) {
            $status = self::SEVERITY_STATUS[$r['severity']];
            $items[] = [
                'label' => ($r['cashier'] !== '' ? $r['cashier'] : __('Unknown cashier')).' · '.$r['shop'],
                'value' => $r['value'],
                'text' => $this->dec($r['value'], 1).'%',
                'cells' => [
                    $this->dec($r['value'], 1).'%',
                    $this->dec($r['baseline'], 1).'%',
                    $r['baseline'] > 0 ? '×'.$this->dec($r['value'] / $r['baseline'], 1) : '—',
                    (string) $r['sample'],
                    $this->severityLabel($r['severity']),
                ],
                'tones' => [null, null, null, null, $status === 'none' ? null : $status],
                'status' => $status,
            ];
        }
        if ($items === []) {
            $items[] = ['label' => $allClear, 'value' => 0.0, 'text' => '0%', 'cells' => array_fill(0, count($columns), '—'), 'status' => 'ok'];
        }

        return ['columns' => $columns, 'items' => $items, 'footer' => $this->weekFooter($set).' '.$what];
    }

    private function securityRefundByCashier(array $o, string $diagram): array
    {
        $set = $this->data->get('security_week');

        return $this->cashierRanking($set, $set['refund'], $o, __('No cashier is above the rest of the chain.'), __('Refund share by amount or by count, whichever is further above the chain.'));
    }

    private function securityDiscountByCashier(array $o, string $diagram): array
    {
        $set = $this->data->get('security_week');

        return $this->cashierRanking($set, $set['discount'], $o, __('No cashier is above the rest of the chain.'), __('Share of sale receipts with a discount of :p % or more.', ['p' => $this->dec((float) config('security.deep_discount_pct'), 0)]));
    }

    private function securityCancelledRate(array $o, string $diagram): array
    {
        $set = $this->data->get('security_week');
        $c = $set['chain'];
        $min = (int) config('security.min_receipts');
        if ($c['all'] < $min) {
            return $this->notEnough(__('fewer than :n receipts in the last 7 days.', ['n' => $min]), ['footer' => $this->weekFooter($set)]);
        }

        $pct = $c['cancelled'] / $c['all'] * 100;
        $emptyPct = $c['empty'] / $c['all'] * 100;
        $prev = $set['prev_chain'];
        $prevText = $prev['all'] >= (int) config('security.min_baseline_receipts')
            ? $this->dec($prev['cancelled'] / $prev['all'] * 100, 1).'%'
            : __('n/a');

        $order = ['ok', 'none', 'warn', 'bad'];
        $a = Comparison::rateStatus($pct, $c['all']);
        $b = Comparison::rateStatus($emptyPct, $c['all']);
        $p = [
            'value' => $this->dec($pct, 1).'%',
            'status' => array_search($a, $order, true) >= array_search($b, $order, true) ? $a : $b,
            'note' => __(':n of :m receipts cancelled · :e% of all receipts without lines · previous 7 days: :p', [
                'n' => $c['cancelled'], 'm' => $c['all'], 'e' => $this->dec($emptyPct, 1), 'p' => $prevText,
            ]),
            'footer' => $this->weekFooter($set),
        ];

        return $diagram === 'gauge' ? $p + ['gauge' => ['value' => $pct, 'min' => 0, 'max' => 10, 'marks' => [2, 5]]] : $p;
    }

    private function securitySilentShops(array $o, string $diagram): array
    {
        $set = $this->data->get('security_today');
        if ($set['normal_shops'] === 0) {
            return $this->empty(['footer' => __('Not enough data: a shop needs :n sale receipts in the last 28 days to know its normal hours.', ['n' => (int) config('security.off_hours_min_baseline_receipts')])]);
        }

        $items = [];
        foreach ($set['silent'] as $s) {
            $status = self::SEVERITY_STATUS[$s['severity']];
            $items[] = [
                'label' => $s['shop'],
                'value' => (float) $s['hours'],
                'text' => $s['hours'].' '.__('h'),
                'cells' => [$s['hours'].' '.__('h'), (string) $s['expected'], $this->severityLabel($s['severity'])],
                'tones' => [null, null, $status === 'none' ? null : $status],
                'status' => $status,
            ];
        }
        if ($items === []) {
            $items[] = ['label' => __('Every shop is trading as usual.'), 'value' => 0.0, 'text' => '0', 'cells' => ['—', '—', '—'], 'status' => 'ok'];
        }

        return ['columns' => [__('Hours without a sale'), __('Receipts expected'), __('Level')], 'items' => $items, 'footer' => $this->todayFooter($set)];
    }

    /** Status of a today count: green at 0, the level colour above. */
    private function todayStatus(int $count, string $severity): string
    {
        return $count === 0 ? 'ok' : self::SEVERITY_STATUS[$severity];
    }

    private function securityOffHoursToday(array $o, string $diagram): array
    {
        $set = $this->data->get('security_today');
        if ($set['normal_shops'] === 0) {
            return $this->notEnough(__('a shop needs :n sale receipts in the last 28 days to know its normal hours.', ['n' => (int) config('security.off_hours_min_baseline_receipts')]), ['footer' => $this->todayFooter($set)]);
        }

        return [
            'value' => (string) $set['off']['count'],
            'status' => $this->todayStatus($set['off']['count'], $set['off']['severity']),
            'note' => __('sale receipts in hours with under :p % of the shop volume', ['p' => $this->dec((float) config('security.off_hours_share_pct'), 0)]),
            'footer' => $this->todayFooter($set),
        ];
    }

    private function securityIntegrityFlags(array $o, string $diagram): array
    {
        $set = $this->data->get('security_today');
        if ($set['sale_receipts'] === 0) {
            return $this->empty(['footer' => __('No sale receipts yet today.')]);
        }

        $d = $set['integrity'];

        return [
            'value' => (string) $d['count'],
            'status' => $this->todayStatus($d['count'], $d['severity']),
            'note' => $d['count'] === 0 ? __('no impossible values found') : __('receipts or lines in :n shops', ['n' => $d['shops']]),
            'footer' => $this->todayFooter($set),
        ];
    }

    private function securityNumberGapsDuplicates(array $o, string $diagram): array
    {
        $set = $this->data->get('security_today');
        if ($set['sale_receipts'] === 0) {
            return $this->empty(['footer' => __('No sale receipts yet today.')]);
        }

        $g = $set['gaps'];
        $d = $set['dups'];
        $ranks = array_map(fn (string $s) => self::SEVERITY_RANK[$s], array_filter([$g['severity'], $d['severity']], fn (string $s) => $s !== 'none'));
        $worst = $ranks === [] ? 'none' : array_search(min($ranks), self::SEVERITY_RANK, true);

        return [
            'value' => (string) $g['missing'],
            'status' => $this->todayStatus($g['missing'] + $d['count'], $worst),
            'note' => __('missing numbers in :t tills · :d duplicate looking receipts', ['t' => $g['tills'], 'd' => $d['count']]),
            'footer' => $this->todayFooter($set),
        ];
    }

    private function securityUnusualBaskets(array $o, string $diagram): array
    {
        $set = $this->data->get('security_today');
        if ($set['basket_shops'] === 0) {
            return $this->empty(['footer' => __('Not enough data: a shop needs :n sale receipts today.', ['n' => (int) config('security.basket_min_receipts')])]);
        }

        $columns = [__('Receipt total'), __('Big-receipt cut'), __('Times higher'), __('Level')];
        $n = max(1, min(WidgetCatalog::TOP_N_MAX, (int) ($o['top_n'] ?? WidgetCatalog::TOP_N_DEFAULT)));
        $items = [];
        foreach (array_slice($set['baskets'], 0, $n) as $b) {
            $status = self::SEVERITY_STATUS[$b['severity']];
            $items[] = [
                'label' => $b['shop'],
                'value' => $b['total'],
                'text' => $this->money($b['total']),
                'cells' => [$this->money($b['total']), $this->money($b['cut']), $b['cut'] > 0 ? '×'.$this->dec($b['total'] / $b['cut'], 1) : '—', $this->severityLabel($b['severity'])],
                'tones' => [null, null, null, $status === 'none' ? null : $status],
                'status' => $status,
            ];
        }
        if ($items === []) {
            $items[] = ['label' => __('No unusual single-item receipt today.'), 'value' => 0.0, 'text' => '0', 'cells' => array_fill(0, count($columns), '—'), 'status' => 'ok'];
        }

        return ['columns' => $columns, 'items' => $items, 'footer' => $this->todayFooter($set)];
    }

    private function dataHealthSilentShops(array $o, string $diagram): array
    {
        $set = $this->data->get('data_health');
        if ($set['judged'] === 0) {
            return $this->empty(['footer' => __('Not enough data: a shop needs a usual day of at least :n receipts to be judged.', ['n' => (int) config('receipts.health.min_usual', 20)])]);
        }

        $items = [];
        foreach ($set['problems'] as $s) {
            $items[] = [
                'label' => $s['shop'],
                'value' => (float) $s['last24'],
                'text' => (string) $s['last24'],
                'cells' => [(string) $s['last24'], $this->dec((float) $s['usual'], 0), $s['status'] === 'bad' ? __('Silent') : __('Low')],
                'tones' => [null, null, $s['status']],
                'status' => $s['status'],
            ];
        }
        if ($items === []) {
            $items[] = ['label' => __('Every feed looks normal.'), 'value' => 0.0, 'text' => '0', 'cells' => ['—', '—', '—'], 'status' => 'ok'];
        }
        // Silent shops first, then by the fewest receipts.
        usort($items, fn (array $a, array $b) => [$a['status'] === 'bad' ? 0 : 1, $a['value']] <=> [$b['status'] === 'bad' ? 0 : 1, $b['value']]);

        return [
            'columns' => [__('Receipts, last 24 h'), __('Usual day'), __('Status')],
            'items' => $items,
            'footer' => __('Until :time. :n shops judged.', ['time' => $set['as_of'], 'n' => $set['judged']]),
        ];
    }

    private function dataHealthRejections24h(array $o, string $diagram): array
    {
        $set = $this->data->get('data_health');
        $r = $set['rejected'];
        $labels = ReceiptRejections::labels();
        arsort($r['by_reason']);
        $top = array_key_first($r['by_reason']);

        return [
            'value' => (string) $r['total'],
            'status' => $r['spike'] ? 'bad' : ($r['total'] > 0 ? 'warn' : 'ok'),
            'note' => $top === null ? __('no receipt was refused') : __('mostly: :reason', ['reason' => __($labels[$top] ?? $top)]),
            'footer' => __('Last 24 hours until :time.', ['time' => $set['as_of']]),
        ];
    }

    private function syncFreshness(array $o, string $diagram): array
    {
        $set = $this->data->get('sync');
        if ($set['hours'] === null) {
            return $this->empty();
        }

        $h = $set['hours'];

        return [
            'value' => $h < 1 ? '< 1 '.__('h') : $this->dec($h, 0).' '.__('h'),
            'status' => $h > 48 ? 'bad' : ($h > 24 ? 'warn' : 'ok'),
            'note' => __('Last stock date: :date', ['date' => $set['stock_date']]),
            'footer' => __('Measured from the end of the stock date, as only the day is stored.'),
        ];
    }
}
