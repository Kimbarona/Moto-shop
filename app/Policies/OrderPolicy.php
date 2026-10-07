<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Order;
use App\Models\User;

/**
 * Orders (spec §2):
 *  - admins and cashiers create orders and edit any Pending order
 *  - mechanics edit / mark done only Pending service orders assigned to them
 *  - payment: admins and cashiers; void: admins, or cashiers with a manager PIN
 *    (the PIN itself is enforced by VoidService)
 *  - Paid orders are locked (later changes go through Return / Refund); nothing is deleted
 */
class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('use-service-board') || $user->can('use-pos');
    }

    public function view(User $user, Order $order): bool
    {
        if ($user->hasRole(Role::SuperAdmin, Role::Admin, Role::Cashier)) {
            return $user->is_active;
        }

        return $user->is_active && $user->isMechanic() && $order->isAssignedTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can('use-pos');
    }

    public function editItems(User $user, Order $order): bool
    {
        if (! $order->isPending() || ! $user->is_active) {
            return false;
        }

        if ($user->hasRole(Role::SuperAdmin, Role::Admin, Role::Cashier)) {
            return true;
        }

        return $user->isMechanic() && $order->isService() && $order->isAssignedTo($user);
    }

    /**
     * Discounts and price overrides: never mechanics (cashiers may need a PIN).
     */
    public function discount(User $user, Order $order): bool
    {
        return $order->isPending() && $user->can('take-payment');
    }

    public function updateServiceStatus(User $user, Order $order): bool
    {
        return $order->isService() && $this->editItems($user, $order);
    }

    public function pay(User $user, Order $order): bool
    {
        return $order->isPending() && $user->can('take-payment');
    }

    public function void(User $user, Order $order): bool
    {
        return ! $order->isVoided() && $user->can('take-payment');
    }

    /**
     * Return / refund / exchange on a paid order (cashiers also need a manager PIN,
     * enforced by ReturnService).
     */
    public function return(User $user, Order $order): bool
    {
        return $order->isReturnable() && $user->can('take-payment');
    }

    public function update(User $user, Order $order): bool
    {
        return $this->editItems($user, $order);
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
