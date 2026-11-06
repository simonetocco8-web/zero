<?php

namespace App\Policies;

use App\Models\InventoryImport;
use App\Models\InventoryItem;
use App\Models\User;

class InventoryImportPolicy
{
    public function create(User $user): bool
    {
        return $user->can('create', InventoryItem::class);
    }

    public function view(User $user, InventoryImport $import): bool
    {
        return $this->create($user) && $user->retailer->id === $import->retailer_id;
    }

    public function update(User $user, InventoryImport $import): bool
    {
        return $this->view($user, $import);
    }
}
