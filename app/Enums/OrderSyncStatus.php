<?php

namespace App\Enums;

/** Sync of a confirmed order to 1C, separate from the order status. */
enum OrderSyncStatus: string
{
    case NotQueued = 'not_queued';
    case Queued = 'queued';
    case Synced = 'synced';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::NotQueued => __('Not queued'),
            self::Queued => __('Queued'),
            self::Synced => __('Synced'),
            self::Failed => __('Failed'),
        };
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::NotQueued => 'default',
            self::Queued => 'warning',
            self::Synced => 'success',
            self::Failed => 'danger',
        };
    }
}
