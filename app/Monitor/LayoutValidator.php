<?php

namespace App\Monitor;

use Illuminate\Support\Str;

/**
 * Validates and normalises a widget layout against WidgetCatalog. Nothing from the browser is trusted:
 * the builder calls this on every save and keeps only the returned layout (sanitised, collision-free,
 * compact). Errors are translated, human-readable strings.
 */
class LayoutValidator
{
    /**
     * @param  mixed  $input  the raw widget list
     * @return array{layout: array<int, array<string, mixed>>, errors: array<int, string>}
     */
    public static function validate(mixed $input): array
    {
        $errors = [];
        $widgets = [];

        if (! is_array($input)) {
            return ['layout' => [], 'errors' => [__('The layout is not valid.')]];
        }

        if (count($input) > WidgetCatalog::MAX_WIDGETS) {
            $errors[] = __('A monitor can have at most :max widgets.', ['max' => WidgetCatalog::MAX_WIDGETS]);
            $input = array_slice($input, 0, WidgetCatalog::MAX_WIDGETS);
        }

        $seen = [];
        foreach (array_values($input) as $i => $raw) {
            $n = $i + 1;
            if (! is_array($raw)) {
                $errors[] = __('Widget :n is not valid.', ['n' => $n]);

                continue;
            }

            $def = WidgetCatalog::find(is_string($raw['key'] ?? null) ? $raw['key'] : null);
            if ($def === null) {
                $errors[] = __('Widget :n: unknown metric.', ['n' => $n]);

                continue;
            }

            $diagram = is_string($raw['diagram'] ?? null) ? $raw['diagram'] : '';
            if (! $def->supports($diagram)) {
                $errors[] = __('Widget :n (:label): the diagram ":diagram" is not available for this metric.', ['n' => $n, 'label' => $def->title(), 'diagram' => $diagram]);

                continue;
            }

            foreach (['x', 'y', 'w', 'h'] as $field) {
                if (! is_int($raw[$field] ?? null) && ! (is_string($raw[$field] ?? null) && ctype_digit($raw[$field]))) {
                    $errors[] = __('Widget :n (:label): the position and size must be whole numbers.', ['n' => $n, 'label' => $def->title()]);

                    continue 2;
                }
            }
            [$x, $y, $w, $h] = [(int) $raw['x'], (int) $raw['y'], (int) $raw['w'], (int) $raw['h']];

            if ($w < $def->min[0] || $w > $def->max[0] || $h < $def->min[1] || $h > $def->max[1]) {
                $errors[] = __('Widget :n (:label): the size must be between :min and :max.', [
                    'n' => $n, 'label' => $def->title(),
                    'min' => $def->min[0].'x'.$def->min[1], 'max' => $def->max[0].'x'.$def->max[1],
                ]);

                continue;
            }

            if ($x < 0 || $x + $w > WidgetCatalog::COLUMNS || $y < 0 || $y > Grid::MAX_Y) {
                $errors[] = __('Widget :n (:label): it does not fit in the 12-column grid.', ['n' => $n, 'label' => $def->title()]);

                continue;
            }

            $id = is_string($raw['id'] ?? null) && preg_match('/^[0-9a-f-]{36}$/i', $raw['id']) && ! isset($seen[$raw['id']])
                ? $raw['id'] : (string) Str::uuid();
            $seen[$id] = true;

            $widgets[] = [
                'id' => $id,
                'key' => $def->key,
                'diagram' => $diagram,
                'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h,
                'options' => self::options($def, is_array($raw['options'] ?? null) ? $raw['options'] : []),
            ];
        }

        $expensive = count(array_filter($widgets, fn (array $w) => WidgetCatalog::find($w['key'])->cost === 'expensive'));
        if ($expensive > WidgetCatalog::MAX_EXPENSIVE) {
            $errors[] = __('At most :max expensive widgets are allowed (you have :count).', ['max' => WidgetCatalog::MAX_EXPENSIVE, 'count' => $expensive]);
        }

        $heatmaps = count(array_filter($widgets, fn (array $w) => $w['diagram'] === Diagram::Heatmap->value));
        if ($heatmaps > WidgetCatalog::MAX_HEATMAPS) {
            $errors[] = __('At most :max heatmaps are allowed (you have :count).', ['max' => WidgetCatalog::MAX_HEATMAPS, 'count' => $heatmaps]);
        }

        return ['layout' => Grid::resolve($widgets), 'errors' => $errors];
    }

    /**
     * Live limit messages for a layout that is already sanitised (the builder shows them while editing).
     *
     * @param  array<int, array<string, mixed>>  $widgets
     * @return array<int, string>
     */
    public static function limits(array $widgets): array
    {
        return self::validate($widgets)['errors'];
    }

    /** Only the options the widget supports, clamped. */
    private static function options(Widget $def, array $raw): array
    {
        $options = Grid::defaultOptions($def);

        if (in_array('top_n', $def->options, true) && isset($raw['top_n']) && is_numeric($raw['top_n'])) {
            $options['top_n'] = max(1, min(WidgetCatalog::TOP_N_MAX, (int) $raw['top_n']));
        }
        if (in_array('metric', $def->options, true) && in_array($raw['metric'] ?? null, ['sum', 'count'], true)) {
            $options['metric'] = $raw['metric'];
        }
        if (is_string($raw['title'] ?? null) && trim($raw['title']) !== '') {
            $options['title'] = Str::limit(trim(strip_tags($raw['title'])), 60, '');
        }

        return $options;
    }
}
