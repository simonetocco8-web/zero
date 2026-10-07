<?php

namespace App\Policies;

use App\Models\PayoutRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class PayoutRequestPolicy
{
    public function view(User $user, PayoutRequest $payoutRequest): bool
    {
        return Gate::forUser($user)->allows('view', $payoutRequest->retailer);
    }
}
