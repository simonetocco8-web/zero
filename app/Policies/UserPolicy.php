<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function accessAdministration(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function accessRetailerArea(User $user): bool
    {
        return $user->role === UserRole::Retailer;
    }
}
