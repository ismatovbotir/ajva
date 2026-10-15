<?php

namespace App\Enums;

enum OrderStatus: string
{
    /** Editable list prepared from the Ordering report; becomes `confirmed` when the shop's person confirms it. */
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    /** Approved by an admin and queued for 1C (replaces the older `approved`, which stays valid). */
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
    case Fulfilled = 'fulfilled';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::PendingApproval => __('Pending approval'),
            self::Approved => __('Approved'),
            self::Confirmed => __('Confirmed'),
            self::Rejected => __('Rejected'),
            self::Fulfilled => __('Fulfilled'),
            self::Cancelled => __('Cancelled'),
        };
    }

    /**
     * Statuses whose goods are still on their way: the quantities leave the warehouse
     * stock and count as in-transit stock of the shop.
     *
     * @return array<int, string>
     */
    public static function open(): array
    {
        return [self::PendingApproval->value, self::Approved->value, self::Confirmed->value];
    }
}
