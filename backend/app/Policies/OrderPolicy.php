<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        if (! $user->hasPermissionKey('orders.view')) {
            return false;
        }

        $isSuperAdmin = $user->roles()->where('name', 'Super Admin')->exists();

        return $isSuperAdmin || $user->branches()->where('branches.id', $order->branch_id)->exists();
    }

    /** Changing an open order (adding rounds, moving table): create-level permission plus branch access. */
    public function update(User $user, Order $order): bool
    {
        return $this->view($user, $order) && $user->hasPermissionKey('orders.create');
    }

    public function cancel(User $user, Order $order): bool
    {
        return $this->view($user, $order) && $user->hasPermissionKey('orders.cancel');
    }
}
