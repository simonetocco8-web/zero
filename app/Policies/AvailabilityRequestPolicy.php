<?php

namespace App\Policies;

use App\Models\AvailabilityRequest;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class AvailabilityRequestPolicy
{
    public function update(User $user, AvailabilityRequest $availabilityRequest): bool
    {
        return $user->retailer && $user->retailer->id === $availabilityRequest->retailer_id && Gate::forUser($user)->allows('operate', $availabilityRequest->retailer);
    }

    public function viewAny(User $user): bool
    {
        return $user->retailer && Gate::forUser($user)->allows('operate', $user->retailer);
    }

    public function view(User $user, AvailabilityRequest $availabilityRequest): bool
    {
        return Gate::forUser($user)->allows('view', $availabilityRequest->retailer);
    }
}
