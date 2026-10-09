<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Turns an arbitrary tool result (nested arrays) into display blocks: key/value
 * lists and tables. Pure presentation, so no data goes anywhere but the page.
 */
class ResultBlocks
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    public static function from(array $data, string $title = ''): array
    {
        $blocks = [];
        $pairs = [];

        foreach ($data as $key => $value) {
            $label = is_int($key) ? '#'.($key + 1) : Str::headline((string) $key);

            if (! is_array($value)) {
                $pairs[] = [$label, self::scalar($value)];
            } elseif ($value === []) {
                $pairs[] = [$label, '—'];
            } elseif (self::isRowList($value)) {
                $blocks[] = self::table($value, $label);
            } elseif (array_is_list($value)) {
                $pairs[] = [$label, implode(', ', array_map(fn ($v) => is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : self::scalar($v), $value))];
            } else {
                array_push($blocks, ...self::from($value, $label));
            }
        }

        if ($pairs !== []) {
            array_unshift($blocks, ['type' => 'kv', 'title' => $title, 'rows' => $pairs]);
        }

        return $blocks;
    }

    private static function isRowList(array $value): bool
    {
        if (! array_is_list($value)) {
            return false;
        }
        foreach ($value as $row) {
            if (! is_array($row) || array_is_list($row)) {
                return false;
            }
        }

        return true;
    }

    private static function table(array $rows, string $title): array
    {
        $columns = [];
        foreach ($rows as $row) {
            foreach (array_keys($row) as $c) {
                $columns[$c] = true;
            }
        }
        $columns = array_keys($columns);

        return [
            'type' => 'table',
            'title' => $title,
            'columns' => array_map(fn ($c) => Str::headline((string) $c), $columns),
            'rows' => array_map(fn ($row) => array_map(function ($c) use ($row) {
                $v = $row[$c] ?? null;

                return is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : self::scalar($v);
            }, $columns), $rows),
        ];
    }

    private static function scalar(mixed $v): string
    {
        return match (true) {
            $v === null => '—',
            is_bool($v) => $v ? __('Yes') : __('No'),
            is_float($v) => rtrim(rtrim(number_format($v, 2, '.', ' '), '0'), '.'),
            is_int($v) => (string) $v,
            default => (string) $v,
        };
    }
}
