<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\PayoutRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class PayoutRequestPolicy
{
    public function review(User $user, PayoutRequest $payoutRequest): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function view(User $user, PayoutRequest $payoutRequest): bool
    {
        return Gate::forUser($user)->allows('view', $payoutRequest->retailer);
    }
}
