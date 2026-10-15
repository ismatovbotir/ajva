<?php

namespace App\Monitor;

use Illuminate\Support\Str;

/**
 * Pure 12-column grid logic shared by the builder (move, resize, add) and the save validator, so a
 * layout is always collision-free and compact no matter who produced it. A widget is an array with
 * id, key, diagram, x, y, w, h, options.
 */
class Grid
{
    public const MAX_Y = 200;

    /** Keeps a widget inside the grid and inside its catalog size limits (unknown keys are left alone). */
    public static function fit(array $widget): array
    {
        $def = WidgetCatalog::find($widget['key'] ?? null);
        $minW = $def ? $def->min[0] : 1;
        $minH = $def ? $def->min[1] : 1;
        $maxW = min($def ? $def->max[0] : WidgetCatalog::COLUMNS, WidgetCatalog::COLUMNS);
        $maxH = $def ? $def->max[1] : 12;

        $widget['w'] = max($minW, min($maxW, (int) ($widget['w'] ?? $minW)));
        $widget['h'] = max($minH, min($maxH, (int) ($widget['h'] ?? $minH)));
        $widget['x'] = max(0, min(WidgetCatalog::COLUMNS - $widget['w'], (int) ($widget['x'] ?? 0)));
        $widget['y'] = max(0, min(self::MAX_Y, (int) ($widget['y'] ?? 0)));

        return $widget;
    }

    public static function overlaps(array $a, array $b): bool
    {
        return $a['x'] < $b['x'] + $b['w'] && $b['x'] < $a['x'] + $a['w']
            && $a['y'] < $b['y'] + $b['h'] && $b['y'] < $a['y'] + $a['h'];
    }

    /**
     * Removes every overlap and packs the layout upwards. Widgets are placed in reading order
     * (y, then the pinned one, then x), each at the lowest row where it fits, so the widget the user
     * just moved wins its spot and the ones it lands on are pushed down (or fall into the gap it left).
     *
     * @param  array<int, array<string, mixed>>  $widgets
     * @return array<int, array<string, mixed>>
     */
    public static function resolve(array $widgets, ?string $pinnedId = null): array
    {
        $widgets = array_map([self::class, 'fit'], array_values($widgets));
        usort($widgets, fn (array $a, array $b) => [$a['y'], $a['id'] === $pinnedId ? 0 : 1, $a['x']] <=> [$b['y'], $b['id'] === $pinnedId ? 0 : 1, $b['x']]);

        $placed = [];
        foreach ($widgets as $widget) {
            $widget['y'] = 0;
            while (self::collides($widget, $placed)) {
                $widget['y']++;
            }
            $placed[] = $widget;
        }

        return $placed;
    }

    /** @param  array<int, array<string, mixed>>  $widgets */
    public static function move(array $widgets, string $id, int $x, int $y): array
    {
        foreach ($widgets as &$widget) {
            if ($widget['id'] === $id) {
                $widget['x'] = $x;
                $widget['y'] = $y;
            }
        }
        unset($widget);

        return self::resolve($widgets, $id);
    }

    /** @param  array<int, array<string, mixed>>  $widgets */
    public static function resize(array $widgets, string $id, int $w, int $h): array
    {
        foreach ($widgets as &$widget) {
            if ($widget['id'] === $id) {
                $widget['w'] = $w;
                $widget['h'] = $h;
                // A widget that would run out of the grid on the right keeps its width and slides left instead of shrinking.
                $def = WidgetCatalog::find($widget['key']);
                $widget['w'] = max($def ? $def->min[0] : 1, min($widget['w'], $def ? $def->max[0] : WidgetCatalog::COLUMNS, WidgetCatalog::COLUMNS - $widget['x']));
            }
        }
        unset($widget);

        return self::resolve($widgets, $id);
    }

    /**
     * Sets position and size in one step (edge/corner resize and drop from the palette); the size is clamped to the catalog,
     * the widget wins its spot and the ones it lands on are pushed down.
     *
     * @param  array<int, array<string, mixed>>  $widgets
     */
    public static function place(array $widgets, string $id, int $x, int $y, int $w, int $h): array
    {
        foreach ($widgets as &$widget) {
            if ($widget['id'] === $id) {
                [$widget['x'], $widget['y'], $widget['w'], $widget['h']] = [$x, $y, $w, $h];
            }
        }
        unset($widget);

        return self::resolve($widgets, $id);
    }

    /**
     * Appends a new widget with the catalog's default size at the bottom.
     *
     * @param  array<int, array<string, mixed>>  $widgets
     * @return array<int, array<string, mixed>>
     */
    public static function add(array $widgets, Widget $def, ?string $id = null): array
    {
        $bottom = 0;
        foreach ($widgets as $widget) {
            $bottom = max($bottom, $widget['y'] + $widget['h']);
        }

        $widgets[] = self::fit([
            'id' => $id ?? (string) Str::uuid(),
            'key' => $def->key,
            'diagram' => $def->default->value,
            'x' => 0,
            'y' => $bottom,
            'w' => $def->w,
            'h' => $def->h,
            'options' => self::defaultOptions($def),
        ]);

        return self::resolve($widgets);
    }

    /** @return array<string, mixed> */
    public static function defaultOptions(Widget $def): array
    {
        $options = [];
        if (in_array('top_n', $def->options, true)) {
            $options['top_n'] = WidgetCatalog::TOP_N_DEFAULT;
        }
        if (in_array('metric', $def->options, true)) {
            $options['metric'] = 'sum';
        }

        return $options;
    }

    /**
     * Closes the holes left by widgets a viewer may not see: a widget in the same row band (same y and h) next to a
     * hidden one takes over its columns (up to its catalog maximum), then the layout is packed upwards again.
     *
     * @param  array<int, array<string, mixed>>  $widgets  the widgets that stay
     * @param  array<int, array<string, mixed>>  $hidden  the widgets that are left out
     * @return array<int, array<string, mixed>>
     */
    public static function heal(array $widgets, array $hidden): array
    {
        $widgets = array_values($widgets);

        // Hidden widgets side by side in one row band are one hole.
        usort($hidden, fn (array $a, array $b) => [$a['y'], $a['h'], $a['x']] <=> [$b['y'], $b['h'], $b['x']]);
        $holes = [];
        foreach ($hidden as $gone) {
            $last = count($holes) - 1;
            if ($last >= 0 && $holes[$last]['y'] === $gone['y'] && $holes[$last]['h'] === $gone['h'] && $holes[$last]['x'] + $holes[$last]['w'] === $gone['x']) {
                $holes[$last]['w'] += $gone['w'];
            } else {
                $holes[] = ['x' => $gone['x'], 'y' => $gone['y'], 'w' => $gone['w'], 'h' => $gone['h']];
            }
        }

        $room = fn (array $w) => (WidgetCatalog::find($w['key'] ?? null)?->max[0] ?? WidgetCatalog::COLUMNS) - $w['w'];
        foreach ($holes as $hole) {
            $left = $right = null;
            foreach ($widgets as $i => $w) {
                if ($w['y'] !== $hole['y'] || $w['h'] !== $hole['h']) {
                    continue;
                }
                if ($hole['x'] === $w['x'] + $w['w']) {
                    $left = $i;
                } elseif ($w['x'] === $hole['x'] + $hole['w']) {
                    $right = $i;
                }
            }

            $rest = $hole['w'];
            if ($left !== null) {
                $take = min($right !== null ? (int) ceil($rest / 2) : $rest, $room($widgets[$left]));
                $widgets[$left]['w'] += $take;
                $rest -= $take;
            }
            if ($right !== null && $rest > 0) {
                $take = min($rest, $room($widgets[$right]));
                $widgets[$right]['x'] -= $take;
                $widgets[$right]['w'] += $take;
                $rest -= $take;
            }
            if ($left !== null && $rest > 0) {
                $take = min($rest, $room($widgets[$left]));
                $widgets[$left]['w'] += $take;
            }
        }

        return self::resolve($widgets);
    }

    /** Reading order (y, then x): the order widgets stack in on narrow screens. */
    public static function readingOrder(array $widgets): array
    {
        usort($widgets, fn (array $a, array $b) => [$a['y'], $a['x']] <=> [$b['y'], $b['x']]);

        return array_values($widgets);
    }

    private static function collides(array $widget, array $placed): bool
    {
        foreach ($placed as $other) {
            if (self::overlaps($widget, $other)) {
                return true;
            }
        }

        return false;
    }
}
