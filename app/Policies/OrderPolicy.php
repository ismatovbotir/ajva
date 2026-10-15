<?php

namespace App\Policies;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;
use App\Support\ShopAccess;

/**
 * Role matrix: the admin gets the Ordering report and generates the drafts (generate) and may do everything
 * on any shop's order; the shop's responsible person (operator assigned to the shop via shop_user) sees only
 * their shops' orders, edits and confirms a DRAFT. Nothing is editable once an order is confirmed.
 */
class OrderPolicy
{
    private function isAdmin(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    /** The responsible person of the order's shop, or an admin. */
    private function manages(User $user, Order $order): bool
    {
        return $this->isAdmin($user)
            || ($user->role === UserRole::Operator && ShopAccess::allows($order->shop_id, $user));
    }

    /** Ordering report and "Prepare order": admin only. */
    public function generate(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Order $order): bool
    {
        return $this->manages($user, $order);
    }

    /** Quantities, lines and added items change only while the order is a draft. */
    public function edit(User $user, Order $order): bool
    {
        return $order->status === OrderStatus::Draft && $this->manages($user, $order);
    }

    /** A draft is confirmed by its shop's person or an admin; older pending_approval orders by an admin. */
    public function confirm(User $user, Order $order): bool
    {
        return match ($order->status) {
            OrderStatus::Draft => $this->manages($user, $order),
            OrderStatus::PendingApproval, OrderStatus::Approved => $this->isAdmin($user),
            default => false,
        };
    }

    /** Only an admin deletes, and only before confirmation. */
    public function delete(User $user, Order $order): bool
    {
        return $order->status === OrderStatus::Draft && $this->isAdmin($user);
    }

    public function retrySync(User $user, Order $order): bool
    {
        return $this->isAdmin($user);
    }
}
