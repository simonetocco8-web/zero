<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Sale;
use App\Models\User;

class SalePolicy
{
    public function view(User $user, Sale $sale): bool
    {
        return $user->role === UserRole::Admin;
    }
}
