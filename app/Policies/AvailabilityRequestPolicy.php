<?php

namespace App\Policies;

use App\Models\AvailabilityRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class AvailabilityRequestPolicy
{
    public function view(User $user, AvailabilityRequest $availabilityRequest): bool
    {
        return Gate::forUser($user)->allows('view', $availabilityRequest->retailer);
    }
}
