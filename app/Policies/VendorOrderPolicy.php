<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;
use App\Models\VendorOrder;

class VendorOrderPolicy
{
    public function viewSale(User $user, VendorOrder $order): bool
    {
        return $user->role === UserRole::Admin || ($user->role === UserRole::Retailer && $user->retailer?->id === $order->retailer_id);
    }

    public function fulfil(User $user, VendorOrder $order): bool
    {
        return $user->role === UserRole::Admin || ($this->viewSale($user, $order) && $user->retailer?->status->value === 'approved');
    }

    public function viewDelivery(User $user, VendorOrder $order): bool
    {
        return $user->can('purchase', User::class) && $order->order->user_id === $user->id;
    }

    public function view(User $user, VendorOrder $order): bool
    {
        return $user->role === UserRole::Admin || ($user->can('purchase', User::class) && $order->order->user_id === $user->id) || ($user->role === UserRole::Retailer && $user->retailer?->id === $order->retailer_id);
    }
}
