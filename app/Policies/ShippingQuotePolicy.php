<?php

namespace App\Policies;

use App\Models\ShippingQuote;
use App\Models\User;

class ShippingQuotePolicy
{
    public function create(User $user): bool
    {
        return $user->can('purchase', User::class);
    }

    public function view(User $user, ShippingQuote $quote): bool
    {
        return $quote->user_id === $user->id && $this->create($user);
    }
}
