<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VendorOrder;
use Illuminate\Support\Facades\Gate;

class VendorOrderPolicy
{
    public function update(User $user, VendorOrder $order): bool
    {
        return Gate::forUser($user)->allows('operate', $order->retailer);
    }
}
