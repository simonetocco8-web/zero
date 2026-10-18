<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class UserPolicy
{
    public function accessMarketplace(User $user): bool
    {
        return in_array($user->role, [UserRole::Admin, UserRole::Customer], true)
            || ($user->role === UserRole::Retailer && $user->retailer?->status->value === 'approved');
    }

    public function purchase(User $user): bool
    {
        return in_array($user->role, [UserRole::Customer, UserRole::Retailer], true);
    }

    public function proposeExchange(User $user): bool
    {
        $retailer = $user->retailer;
        $subscription = $retailer?->activeSubscription;

        return $user->role === UserRole::Retailer && $retailer?->status->value === 'approved'
            && $subscription && $subscription->plan->code === 'pro' && $subscription->plan->exchange_available
            && $subscription->starts_at <= now() && (! $subscription->ends_at || $subscription->ends_at > now());
    }

    public function accessAdministration(User $user): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function accessRetailerArea(User $user): bool
    {
        return $user->role === UserRole::Retailer;
    }
}
