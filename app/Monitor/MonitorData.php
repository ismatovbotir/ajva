<?php

namespace App\Monitor;

use App\Services\ForecastService;
use App\Support\MonitorScope;
use Illuminate\Support\Carbon;

/**
 * The shared pass of a custom monitor: from a layout and a MonitorScope to one payload per widget.
 *
 * What is NOT in the result (rather than hidden in Blade):
 *  - profit widgets when the scope does not allow profit (and their cost queries never run);
 *  - widgets that are not public safe (receipt-level data) on public links.
 * A widget key that no longer exists in the catalog becomes a "Widget removed" placeholder, never an error.
 * Datasets are built once and only for the widgets that are present (see Datasets).
 */
class MonitorData
{
    /**
     * @param  array<int, array<string, mixed>>|null  $layout  unsaved layout (builder preview); null = the monitor's saved one
     * @param  bool  $sample  builder canvas: generated sample data (SampleDatasets), no queries, nothing hidden or healed
     * @return array{widgets: array<int, array<string, mixed>>, datasets: array<int, string>}
     */
    public static function build(MonitorScope $scope, ?array $layout = null, bool $sample = false): array
    {
        $layout = Grid::readingOrder($layout ?? $scope->monitor->widgets());

        // Widgets this viewer may not see leave a hole; the rest is healed (neighbour widens, rows move up).
        $hidden = [];
        $kept = [];
        foreach ($layout as $widget) {
            $def = WidgetCatalog::find($widget['key'] ?? null);
            if (! $sample && $def !== null && (($def->profit && ! $scope->withProfit) || (! $def->publicSafe && $scope->public))) {
                $hidden[] = $widget;
            } else {
                $kept[] = $widget;
            }
        }
        if ($hidden !== []) {
            $kept = Grid::heal($kept, $hidden);
        }

        $visible = [];
        $placeholders = [];
        foreach ($kept as $widget) {
            $def = WidgetCatalog::find($widget['key'] ?? null);
            if ($def === null) {
                $placeholders[] = $widget;

                continue;
            }
            if (! $def->supports($widget['diagram'] ?? '')) {
                $widget['diagram'] = $def->default->value;
            }
            $visible[] = [$def, $widget];
        }

        $ttls = [];
        foreach ($visible as [$def, $widget]) {
            foreach ($def->datasets($widget['diagram']) as $set) {
                $ttls[$set] = min($ttls[$set] ?? PHP_INT_MAX, $def->ttl);
            }
        }

        $data = $sample ? new SampleDatasets($scope) : new Datasets($scope, $ttls);
        $payloads = new WidgetPayloads($data, $scope);

        $out = [];
        foreach (array_merge(array_map(fn ($w) => [null, $w], $placeholders), $visible) as [$def, $widget]) {
            $out[] = $def === null ? self::removed($widget) : self::envelope($def, $widget, $payloads->build($def, $widget), $data, $scope);
        }

        usort($out, fn (array $a, array $b) => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);

        return ['widgets' => $out, 'datasets' => $data->built()];
    }

    /** @return array<string, mixed> */
    private static function removed(array $widget): array
    {
        return [
            'id' => (string) ($widget['id'] ?? ''),
            'key' => (string) ($widget['key'] ?? ''),
            'state' => 'removed',
            'status' => 'none',
            'title' => __('Widget removed'),
            'diagram' => 'kpi',
            'x' => (int) ($widget['x'] ?? 0), 'y' => (int) ($widget['y'] ?? 0),
            'w' => max(1, (int) ($widget['w'] ?? 3)), 'h' => max(1, (int) ($widget['h'] ?? 2)),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function envelope(Widget $def, array $widget, array $payload, Datasets $data, MonitorScope $scope): array
    {
        $generated = $data->get($def->datasets($widget['diagram'])[0])['generated_at'] ?? $scope->now->timestamp;
        $age = max(0, $scope->now->timestamp - $generated);
        $options = $widget['options'] ?? [];

        $caveat = null;
        if ($def->comparison && (int) $scope->now->format('G') < 9) {
            $caveat = __('Early in the day: few receipts, so the comparison is not reliable yet.');
        }

        return $payload + [
            'footer' => null,
        ] + [
            'id' => (string) $widget['id'],
            'key' => $def->key,
            'title' => ($options['title'] ?? '') !== '' ? $options['title'] : $def->title(),
            'diagram' => $widget['diagram'],
            'shape' => $def->shape,
            'group' => $def->groupLabel(),
            'x' => $widget['x'], 'y' => $widget['y'], 'w' => $widget['w'], 'h' => $widget['h'],
            'comparison' => $def->comparison,
            'incomplete' => (bool) ($payload['compare']['incomplete'] ?? false),
            'caveat' => $caveat,
            'rule' => $def->rule === null ? null : __($def->rule, ['days' => $data instanceof SampleDatasets ? max(1, min(7, (int) config('inventory.order_horizon_days', 3))) : ForecastService::horizon()]),
            'fresh' => [
                'time' => Carbon::createFromTimestamp($generated, config('app.timezone'))->format('H:i:s'),
                'stale' => $age > max(90, $def->ttl * 3),
            ],
        ];
    }
}
