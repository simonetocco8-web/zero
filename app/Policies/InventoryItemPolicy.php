<?php

namespace App\Policies;

use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class InventoryItemPolicy
{
    public function view(User $user, InventoryItem $inventoryItem): bool
    {
        return Gate::forUser($user)->allows('view', $inventoryItem->retailer);
    }
}
