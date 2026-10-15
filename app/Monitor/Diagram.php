<?php

namespace App\Monitor;

/** The ways a widget can be drawn. Which ones a widget supports is declared in WidgetCatalog. */
enum Diagram: string
{
    case Kpi = 'kpi';
    case Sparkline = 'sparkline';
    case Gauge = 'gauge';
    case Line = 'line';
    case Bar = 'bar';
    case StackedBar = 'stacked_bar';
    case Donut = 'donut';
    case Table = 'table';
    case Heatmap = 'heatmap';
    case Ticker = 'ticker';

    public function label(): string
    {
        return match ($this) {
            self::Kpi => __('Number'),
            self::Sparkline => __('Sparkline'),
            self::Gauge => __('Gauge'),
            self::Line => __('Line chart'),
            self::Bar => __('Bar chart'),
            self::StackedBar => __('Stacked bar'),
            self::Donut => __('Donut'),
            self::Table => __('Table'),
            self::Heatmap => __('Heatmap'),
            self::Ticker => __('Ticker'),
        };
    }

    /**
     * Diagrams a data shape can be drawn as (the catalog may only list a subset):
     * single value = kpi / gauge (rates only) / sparkline (only with a series); hourly or daily series = line / bar;
     * by shop = bar / table / heatmap (donut up to 7 shops); by item = table / bar; parts = donut / stacked bar.
     *
     * @return array<int, self>
     */
    public static function forShape(string $shape): array
    {
        return match ($shape) {
            'single' => [self::Kpi, self::Gauge, self::Sparkline],
            'series' => [self::Line, self::Bar],
            'by_shop' => [self::Bar, self::Table, self::Heatmap, self::Donut],
            'by_item' => [self::Table, self::Bar],
            'parts' => [self::Donut, self::StackedBar],
            'events' => [self::Ticker],
            default => [],
        };
    }
}
