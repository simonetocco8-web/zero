<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class SubscriptionPolicy
{
    public function view(User $user, Subscription $subscription): bool
    {
        return Gate::forUser($user)->allows('view', $subscription->retailer);
    }
}
