<?php

namespace App\Policies;

use App\Models\CustomerAddress;
use App\Models\User;

class CustomerAddressPolicy
{
    public function create(User $user): bool
    {
        return $user->can('purchase', User::class);
    }

    public function view(User $user, CustomerAddress $address): bool
    {
        return $this->create($user) && $address->user_id === $user->id;
    }

    public function update(User $user, CustomerAddress $address): bool
    {
        return $this->view($user, $address);
    }

    public function delete(User $user, CustomerAddress $address): bool
    {
        return $this->view($user, $address);
    }
}
