<?php

namespace App\Policies;

use App\Models\InventoryImage;
use App\Models\User;
use App\Services\PublicInventory;
use Illuminate\Support\Facades\Gate;

class InventoryImagePolicy
{
    public function viewPublic(?User $user, InventoryImage $image): bool
    {
        return $user && $user->can('accessMarketplace', User::class) && app(PublicInventory::class)->isAvailable($image->inventoryItem);
    }

    public function view(User $user, InventoryImage $inventoryImage): bool
    {
        return Gate::forUser($user)->allows('view', $inventoryImage->inventoryItem->retailer);
    }
}
