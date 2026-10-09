<?php

namespace App\Support;

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
            default => [],
        };
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
}
