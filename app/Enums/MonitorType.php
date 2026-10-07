<?php

namespace App\Enums;

/**
 * The monitor types and their registry. A new type is one case here, one Blade
 * view under resources/views/livewire/monitor-types/ and one data method on
 * App\Livewire\MonitorScreen (named by dataMethod()).
 */
enum MonitorType: string
{
    case Executive = 'executive';
    case Receipts = 'receipts';
    case Warehouse = 'warehouse';

    public function label(): string
    {
        return match ($this) {
            self::Executive => __('Executive'),
            self::Receipts => __('Receipts'),
            self::Warehouse => __('Warehouse'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Executive => __('Everything for the current day: sales, payments, shops and latest receipts.'),
            self::Receipts => __('Receipts analytics.'),
            self::Warehouse => __('Order recommendations per shop for the main warehouse.'),
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Executive => 'chart',
            self::Receipts => 'receipt',
            self::Warehouse => 'box',
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Executive => 'success',
            self::Receipts => 'info',
            self::Warehouse => 'warning',
        };
    }

    /** Registry: the Blade view that renders this type inside the shared shell. */
    public function view(): string
    {
        return 'livewire.monitor-types.'.$this->value;
    }

    /** Registry: the MonitorScreen method returning this type's view data (see MonitorScope). */
    public function dataMethod(): string
    {
        return $this->value.'Data';
    }

    /** The main warehouse is never one of the "shops to replenish". */
    public function excludesWarehouse(): bool
    {
        return $this === self::Warehouse;
    }
}
