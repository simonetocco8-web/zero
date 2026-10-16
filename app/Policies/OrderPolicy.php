<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function view(User $user, Order $order): bool
    {
        return $user->role === UserRole::Admin || $user->can('purchase', User::class) && $order->user_id === $user->id;
    }
}
