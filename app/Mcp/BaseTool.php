<?php

namespace App\Mcp;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

abstract class BaseTool implements Tool
{
    protected const DATE_SCHEMA = [
        'type' => 'string',
        'description' => 'Day as YYYY-MM-DD (POS receipt time). Defaults to today.',
        'pattern' => '^\d{4}-\d{2}-\d{2}$',
    ];

    protected const SHOP_SCHEMA = [
        'type' => 'integer',
        'description' => 'Limit to one shop id (see list_shops). Omit for all shops.',
    ];

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function dayRange(array $arguments): array
    {
        $raw = $arguments['date'] ?? null;

        if ($raw === null || $raw === '') {
            $day = now()->startOfDay();
        } else {
            try {
                $day = Carbon::createFromFormat('!Y-m-d', (string) $raw);
            } catch (\Throwable) {
                $day = false;
            }

            if (! $day || $day->format('Y-m-d') !== $raw) {
                throw new InvalidArguments('date must be a valid day formatted as YYYY-MM-DD.');
            }
        }

        return [$day->copy()->startOfDay(), $day->copy()->endOfDay()];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function optionalInt(array $arguments, string $key, ?int $min = null, ?int $max = null): ?int
    {
        if (! isset($arguments[$key])) {
            return null;
        }

        $value = $arguments[$key];
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArguments("{$key} must be an integer.");
        }

        $value = (int) $value;
        if (($min !== null && $value < $min) || ($max !== null && $value > $max)) {
            throw new InvalidArguments("{$key} must be between {$min} and {$max}.");
        }

        return $value;
    }

    protected function hourExpression(string $column = 'receipts.created_at'): string
    {
        return DB::getDriverName() === 'sqlite'
            ? "CAST(strftime('%H', {$column}) AS INTEGER)"
            : "HOUR({$column})";
    }

    protected function money(float|int|string|null $value): float
    {
        return round((float) $value, 2);
    }
}
