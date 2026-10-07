<?php

namespace App\Policies;

use App\Enums\InventoryStatus;
use App\Enums\UserRole;
use App\Models\InventoryItem;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

class InventoryItemPolicy
{
    public function create(User $user): bool
    {
        return $user->retailer && Gate::forUser($user)->allows('operate', $user->retailer);
    }

    public function update(User $user, InventoryItem $inventoryItem): bool
    {
        return $this->create($user) && $inventoryItem->retailer_id === $user->retailer->id && $inventoryItem->status !== InventoryStatus::Archived;
    }

    public function archive(User $user, InventoryItem $inventoryItem): bool
    {
        return $this->update($user, $inventoryItem) && in_array($inventoryItem->status->value, ['draft', 'pending', 'rejected']) && ! $inventoryItem->published_at && ! $inventoryItem->external_product_id;
    }

    public function review(User $user, InventoryItem $inventoryItem): bool
    {
        return $user->role === UserRole::Admin;
    }

    public function view(User $user, InventoryItem $inventoryItem): bool
    {
        return Gate::forUser($user)->allows('view', $inventoryItem->retailer);
    }
}
