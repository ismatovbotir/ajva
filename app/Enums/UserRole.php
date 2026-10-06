<?php

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Operator = 'operator';
    case Monitor = 'monitor';

    public function label(): string
    {
        return match ($this) {
            self::Admin => __('Admin'),
            self::Operator => __('Operator'),
            self::Monitor => __('Monitor'),
        };
    }

    /** What the role may do, shown next to the role picker. */
    public function description(): string
    {
        return match ($this) {
            self::Admin => __('Full access, including settings and users.'),
            self::Operator => __('Everything except settings.'),
            self::Monitor => __('Only the live monitor screen.'),
        };
    }

    /** Route a user with this role lands on after signing in. */
    public function homeRoute(): string
    {
        return $this === self::Monitor ? 'monitor' : 'dashboard';
    }

    public function badgeVariant(): string
    {
        return match ($this) {
            self::Admin => 'danger',
            self::Operator => 'info',
            self::Monitor => 'default',
        };
    }
}
