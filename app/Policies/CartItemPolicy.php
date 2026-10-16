<?php

namespace App\Policies;

use App\Models\CartItem;
use App\Models\User;

class CartItemPolicy
{
    public function update(User $user, CartItem $item): bool
    {
        return $user->can('purchase', User::class) && $item->cart->user_id === $user->id;
    }

    public function delete(User $user, CartItem $item): bool
    {
        return $this->update($user, $item);
    }
}
