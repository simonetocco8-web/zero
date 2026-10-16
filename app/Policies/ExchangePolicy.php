<?php

namespace App\Policies;

use App\Models\Exchange;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class ExchangePolicy
{
    public function update(User $user, Exchange $exchange): bool
    {
        return Gate::forUser($user)->allows('operate', $exchange->retailer);
    }
}
