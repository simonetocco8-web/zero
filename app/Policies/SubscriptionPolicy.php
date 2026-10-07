<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class SubscriptionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Retailer && $user->retailer !== null;
    }

    public function checkout(User $user): bool
    {
        return $this->viewAny($user) && in_array($user->retailer->status->value, ['pending', 'approved'], true);
    }

    public function view(User $user, Subscription $subscription): bool
    {
        return Gate::forUser($user)->allows('view', $subscription->retailer);
    }
}
