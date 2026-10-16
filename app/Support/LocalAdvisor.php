<?php

namespace App\Support;

use App\Services\AssortmentAnalysis;

/**
 * Rule-based observations over a tool result. Runs entirely inside the app:
 * AjvaAI never sends business data to the AI service, so the advice that
 * depends on numbers is produced here, from fixed thresholds.
 *
 * @phpstan-type Note array{level: 'warn'|'info', text: string}
 */
class LocalAdvisor
{
    /**
     * @param  array<string, mixed>  $data  a tool's result
     * @return array<int, array{level: string, text: string}>
     */
    public static function notes(string $tool, array $data): array
    {
        return match ($tool) {
            'receipt_analytics' => self::receiptAnalytics($data['report'] ?? null),
            'sales_summary' => self::salesSummary($data['totals'] ?? []),
            'stock_levels' => self::stockLevels($data['rows'] ?? []),
            'item_prices' => self::itemPrices($data['items'] ?? []),
            'list_orders' => self::orders($data['orders'] ?? []),
            'assortment_analysis' => self::assortment($data['report'] ?? null),
            'unsold_stock' => self::unsoldStock($data['report'] ?? null),
            default => [],
        };
    }

    /**
     * Conclusions of the assortment analysis (ABC x XYZ, dead / slow stock, A items out of stock). Thresholds are fixed
     * and only counts and shares are used; every note names what to do, not just what is wrong.
     */
    private static function assortment(?array $report): array
    {
        if (! $report || empty($report['kpi']) || ($report['kpi']['items'] ?? 0) === 0) {
            return [];
        }

        $k = $report['kpi'];
        $w = $report['window'];
        $notes = [];

        $notes[] = ['level' => 'info', 'text' => __(':n items (:p % of the items sold) bring 80 % of sales (class A). Keep them in stock every day and never let them run out.', ['n' => $k['a_items'], 'p' => self::fmt($k['a_items_share'])])];

        if ($k['a_items_share'] < 10 && $k['items'] >= 50) {
            $notes[] = ['level' => 'info', 'text' => __('Sales are very concentrated: fewer than 10 % of the items carry 80 % of the revenue. Protect those items first, the rest of the range matters much less.')];
        }

        if ($k['a_at_risk'] > 0) {
            $notes[] = ['level' => 'warn', 'text' => __(':n class A items are out of stock in at least one shop right now. Replenish these first: every day without them is lost sales (see the list below).', ['n' => $k['a_at_risk']])];
        }

        if ($w['xyz']) {
            if (($k['az_items'] ?? 0) > 0) {
                $notes[] = ['level' => 'info', 'text' => __(':n important items (class A) sell erratically (Z). Check promotions, price changes and out-of-stock weeks before changing their stock, and keep a safety stock instead of cutting them.', ['n' => $k['az_items']])];
            }
            if (($k['cz_items'] ?? 0) > 0 && ($k['cz_share_items'] ?? 0) >= 10) {
                $notes[] = ['level' => 'warn', 'text' => __(':n items (:p % of the items sold) give only :r % of sales and sell erratically (class C, Z). Candidates to delist, to order only on demand, or to stop restocking.', ['n' => $k['cz_items'], 'p' => self::fmt($k['cz_share_items']), 'r' => self::fmt($k['cz_share_revenue'] ?? 0)])];
            }
        } else {
            $notes[] = ['level' => 'info', 'text' => __('XYZ needs at least :w full weeks of sales, this period has :n. Choose a longer period (12 weeks is recommended) to see demand stability.', ['w' => AssortmentAnalysis::MIN_WEEKS_XYZ, 'n' => $w['weeks']])];
        }

        if ($k['dead'] > 0) {
            $text = __(':n items have stock but no sale in :d days. Check the price and display, run a promotion, return them to the supplier or write them off. Items that only just arrived also appear here.', ['n' => $k['dead'], 'd' => $w['days']]);
            if (! empty($k['stock_value'])) {
                $text .= ' '.__('They tie up :v at cost price.', ['v' => self::fmt($k['stock_value']['dead'], 0)]);
                if ($k['stock_value']['dead_without_cost'] > 0) {
                    $text .= ' '.__(':n of them have no cost price and are left out of that figure.', ['n' => $k['stock_value']['dead_without_cost']]);
                }
            }
            $notes[] = ['level' => 'warn', 'text' => $text];
        }

        if ($k['slow'] > 0) {
            $notes[] = ['level' => 'info', 'text' => __(':n items have more than :d days of cover at the current sales speed. Do not reorder them; a promotion or a transfer to a faster shop would release the cash.', ['n' => $k['slow'], 'd' => $k['slow_days']])];
        }

        if ($k['stock_stale'] && $k['stock_as_of']) {
            $notes[] = ['level' => 'warn', 'text' => __('The stock figures are from :date, which is old. Dead stock and out-of-stock conclusions may be out of date until 1C syncs again.', ['date' => $k['stock_as_of']])];
        }

        if (($k['sold_without_stock_row'] ?? 0) > 0) {
            $notes[] = ['level' => 'info', 'text' => __(':n items sold in this period have no stock record in the selected shops, so their cover cannot be judged.', ['n' => $k['sold_without_stock_row']])];
        }

        if (($k['negative_stock_rows'] ?? 0) > 0) {
            $notes[] = ['level' => 'info', 'text' => __(':n stock records are negative (counted as zero). Check receiving and write-offs in 1C.', ['n' => $k['negative_stock_rows']])];
        }

        return $notes;
    }

    /**
     * Conclusions of "stock without sales": how much of the stock does not move, where it concentrates and what to do.
     * Fixed thresholds, counts and shares only.
     */
    private static function unsoldStock(?array $report): array
    {
        if (! $report || empty($report['kpi']) || ($report['kpi']['stocked_items'] ?? 0) === 0) {
            return [];
        }

        $k = $report['kpi'];
        $w = $report['window'];
        $notes = [];

        if ($k['unsold_items'] === 0) {
            return [['level' => 'info', 'text' => __('Every item in stock sold at least once in :d days. Nothing is lying around.', ['d' => $w['days']])]];
        }

        $text = __(':n items (:p % of the items in stock) did not sell at all in :d days, :u units in total. Check the price and the display, run a promotion, transfer them to a shop where they sell, return them to the supplier or write them off.', [
            'n' => $k['unsold_items'], 'p' => self::fmt($k['unsold_share']), 'd' => $w['days'], 'u' => self::fmt($k['unsold_units'], 0),
        ]);
        $notes[] = ['level' => $k['unsold_share'] >= 20 ? 'warn' : 'info', 'text' => $text];

        if (! empty($k['value']) && $k['value']['unsold'] > 0) {
            $line = __('This stock ties up :v at cost price.', ['v' => self::fmt($k['value']['unsold'], 0)]);
            if ($k['value']['without_cost'] > 0) {
                $line .= ' '.__(':n of them have no cost price and are left out of that figure.', ['n' => $k['value']['without_cost']]);
            }
            $notes[] = ['level' => 'warn', 'text' => $line];
        }

        $top = $report['groups'][0] ?? null;
        if ($top !== null && $top['group'] !== '' && $top['unsold'] >= 3) {
            $notes[] = ['level' => 'info', 'text' => __('The group :g has the most unsold items: :n (:p % of its stock). Start the review there.', ['g' => $top['group'], 'n' => $top['unsold'], 'p' => self::fmt($top['share'])])];
        }

        if ($k['unsold_pairs'] > $k['unsold_items']) {
            $notes[] = ['level' => 'info', 'text' => __(':n stock lines sit in shops where the item did not sell. Open the view "Per item and shop": the same item may sell in another shop, so a transfer can release the stock.', ['n' => $k['unsold_pairs']])];
        }

        if ($w['short']) {
            $notes[] = ['level' => 'info', 'text' => __('The period is only :d days, so slow items can look dead. For a safe decision use 60 to 90 days.', ['d' => $w['days']])];
        }

        if ($k['stock_stale'] && $k['stock_as_of']) {
            $notes[] = ['level' => 'warn', 'text' => __('The stock figures are from :date, which is old. Dead stock and out-of-stock conclusions may be out of date until 1C syncs again.', ['date' => $k['stock_as_of']])];
        }

        return $notes;
    }

    private static function pct(float $part, float $whole): float
    {
        return $whole > 0 ? $part / $whole * 100 : 0.0;
    }

    private static function fmt(float $n, int $d = 1): string
    {
        return number_format($n, $d, '.', ' ');
    }

    private static function receiptAnalytics(?array $report): array
    {
        if (! $report || empty($report['kpi'])) {
            return [];
        }

        $k = $report['kpi'];
        $notes = [];

        if ($k['discount_rate'] >= 10) {
            $notes[] = ['level' => 'warn', 'text' => __('Discounts take :p % of gross sales. Check the items with the most discount and whether they really lift the basket.', ['p' => self::fmt($k['discount_rate'])])];
        } elseif ($k['discount_rate'] >= 5) {
            $notes[] = ['level' => 'info', 'text' => __('Discounts take :p % of gross sales. Keep an eye on the items with the most discount.', ['p' => self::fmt($k['discount_rate'])])];
        }

        if ($k['sale_count'] >= 10) {
            $refund = self::pct($k['refund_sum'], $k['sale_sum']);
            if ($refund >= 2) {
                $notes[] = ['level' => $refund >= 5 ? 'warn' : 'info', 'text' => __('Refunds are :p % of sales. Look at which shop, till or item they concentrate in.', ['p' => self::fmt($refund)])];
            }
            $cancelled = self::pct($k['cancelled_count'], $k['all_count']);
            if ($cancelled >= 2) {
                $notes[] = ['level' => $cancelled >= 5 ? 'warn' : 'info', 'text' => __('Cancelled receipts are :p % of all receipts. Check cashiers and tills with unusual cancel counts.', ['p' => self::fmt($cancelled)])];
            }
        }

        $cu = $report['customers'] ?? null;
        if ($cu && $k['sale_count'] >= 20) {
            if ($cu['with']['share'] < 20) {
                $notes[] = ['level' => 'info', 'text' => __('Only :p % of sale receipts have a customer / loyalty card. Ask cashiers to offer the card at every sale; it is the only way to know who buys.', ['p' => self::fmt($cu['with']['share'])])];
            }
            if ($cu['with']['count'] >= 10 && $cu['without']['avg_check'] > 0 && $cu['with']['avg_check'] >= $cu['without']['avg_check'] * 1.2) {
                $notes[] = ['level' => 'info', 'text' => __('Customers with a card spend :p % more per receipt than anonymous buyers. Growing the card base is worth it.', ['p' => self::fmt(($cu['with']['avg_check'] / $cu['without']['avg_check'] - 1) * 100, 0)])];
            }
        }

        $flagged = array_values(array_filter($report['cashiers'] ?? [], fn ($c) => ! empty($c['flags'])));
        if ($flagged !== []) {
            $names = implode(', ', array_map(fn ($c) => ($c['cashier'] ?? __('Unknown')).' ('.$c['shop'].')', array_slice($flagged, 0, 3)));
            $notes[] = ['level' => 'warn', 'text' => __(':n cashiers have refund, cancel or discount rates at least twice the chain average: :names. Review their receipts before drawing conclusions.', ['n' => count($flagged), 'names' => $names])];
        }

        $big = $report['big'] ?? [];
        if (($big['cut'] ?? null) !== null && $big['revenue_share'] >= 30) {
            $notes[] = ['level' => 'info', 'text' => __('The biggest 5 % of receipts bring :p % of revenue. Keep the items typical for them always in stock.', ['p' => self::fmt($big['revenue_share'])])];
        }

        $pair = $report['relations']['pairs'][0] ?? null;
        if ($pair && $pair['lift'] >= 1.5) {
            $notes[] = ['level' => 'info', 'text' => __('":a" and ":b" are bought together much more often than by chance (lift :l). Consider placing them near each other or bundling them.', ['a' => $pair['a'], 'b' => $pair['b'], 'l' => self::fmt($pair['lift'], 2)])];
        }

        return $notes;
    }

    private static function salesSummary(array $t): array
    {
        if (($t['sell_count'] ?? 0) < 10) {
            return [];
        }

        $notes = [];
        $refund = self::pct((float) $t['refund_total'], (float) $t['sell_total']);
        if ($refund >= 2) {
            $notes[] = ['level' => $refund >= 5 ? 'warn' : 'info', 'text' => __('Refunds are :p % of sales for this day.', ['p' => self::fmt($refund)])];
        }
        $cancelled = self::pct((float) $t['cancelled_count'], (float) ($t['sell_count'] + $t['refund_count'] + $t['cancelled_count']));
        if ($cancelled >= 2) {
            $notes[] = ['level' => $cancelled >= 5 ? 'warn' : 'info', 'text' => __('Cancelled receipts are :p % of all receipts for this day.', ['p' => self::fmt($cancelled)])];
        }

        $c = $t['comparison'] ?? null;
        if ($c) {
            $worst = min(array_filter([$c['delta_prev_day_pct'], $c['delta_same_weekday_lw_pct']], fn ($d) => $d !== null) ?: [0]);
            if ($worst < -15) {
                $notes[] = ['level' => 'warn', 'text' => __('Sales are :p % below the same time yesterday or on the same weekday last week. Check which shops and hours fell.', ['p' => self::fmt(abs($worst))])];
            }
        }

        return $notes;
    }

    private static function stockLevels(array $rows): array
    {
        $below = array_values(array_filter($rows, fn ($r) => ! empty($r['below_min'])));
        if ($below === []) {
            return [];
        }

        usort($below, fn ($a, $b) => ($b['min'] - $b['qty']) <=> ($a['min'] - $a['qty']));
        $names = implode(', ', array_map(fn ($r) => $r['item'].' ('.$r['shop'].')', array_slice($below, 0, 3)));

        return [['level' => 'warn', 'text' => __(':n rows are below the minimum. Replenish first: :names.', ['n' => count($below), 'names' => $names])]];
    }

    private static function itemPrices(array $items): array
    {
        $thin = [];
        foreach ($items as $item) {
            foreach ($item['prices'] ?? [] as $p) {
                if (($p['margin_percent'] ?? null) !== null && $p['margin_percent'] < 10) {
                    $thin[$item['item']] = true;
                }
            }
        }

        return $thin === [] ? [] : [['level' => 'warn', 'text' => __(':n items have a sell price margin under 10 %: :names.', ['n' => count($thin), 'names' => implode(', ', array_slice(array_keys($thin), 0, 5))])]];
    }

    private static function orders(array $orders): array
    {
        $notes = [];

        $failed = array_values(array_filter($orders, fn ($o) => ($o['sync_status'] ?? '') === 'failed'));
        if ($failed !== []) {
            $names = implode(', ', array_map(fn ($o) => $o['number'], array_slice($failed, 0, 5)));
            $notes[] = ['level' => 'warn', 'text' => __(':n orders failed to sync to 1C: :names. Open each one and use Retry sync once the cause is fixed.', ['n' => count($failed), 'names' => $names])];
        }

        $unsent = array_values(array_filter($orders, fn ($o) => ($o['status'] ?? '') === 'draft' && in_array($o['telegram_status'] ?? '', ['failed', 'no_recipients', 'no_token'], true)));
        if ($unsent !== []) {
            $names = implode(', ', array_map(fn ($o) => $o['number'], array_slice($unsent, 0, 5)));
            $notes[] = ['level' => 'warn', 'text' => __(':n draft orders did not reach the shop by Telegram: :names. Resend the link or ask the responsible person to open the panel.', ['n' => count($unsent), 'names' => $names])];
        }

        return $notes;
    }
}
