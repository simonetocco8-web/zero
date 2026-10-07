<?php

namespace App\Policies;

use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class SaleItemPolicy
{
    public function view(User $user, SaleItem $saleItem): bool
    {
        return Gate::forUser($user)->allows('view', $saleItem->retailer);
    }
}
