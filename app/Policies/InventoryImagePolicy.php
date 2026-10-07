<?php

namespace App\Policies;

use App\Models\InventoryImage;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class InventoryImagePolicy
{
    public function view(User $user, InventoryImage $inventoryImage): bool
    {
        return Gate::forUser($user)->allows('view', $inventoryImage->inventoryItem->retailer);
    }
}
