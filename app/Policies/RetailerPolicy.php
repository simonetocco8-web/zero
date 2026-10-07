<?php

namespace App\Policies;

use App\Enums\RetailerStatus;
use App\Enums\UserRole;
use App\Models\Retailer;
use App\Models\User;

class RetailerPolicy
{
    public function update(User $user, Retailer $retailer): bool
    {
        return $user->role === UserRole::Retailer && $retailer->user_id === $user->id;
    }

    public function operate(User $user, Retailer $retailer): bool
    {
        return $this->update($user, $retailer) && $retailer->status === RetailerStatus::Approved;
    }

    public function review(User $user, Retailer $retailer): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function view(User $user, Retailer $retailer): bool
    {
        return $user->role === UserRole::Admin
            || ($user->role === UserRole::Retailer && $retailer->user_id === $user->id);
    }
}
