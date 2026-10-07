<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\User;

class PlanPolicy
{
    public function view(User $user, Plan $plan): bool
    {
        return $plan->is_active || $user->role === UserRole::Admin;
    }
}
