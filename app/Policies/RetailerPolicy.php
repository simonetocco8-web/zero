<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Retailer;
use App\Models\User;

class RetailerPolicy
{
    public function view(User $user, Retailer $retailer): bool
    {
        return $user->role === UserRole::Admin
            || ($user->role === UserRole::Retailer && $retailer->user_id === $user->id);
    }
}
