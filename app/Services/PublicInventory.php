<?php

namespace App\Services;

use App\Models\InventoryItem;

class PublicInventory
{
    public function ensureAvailable(InventoryItem $item): void
    {
        abort_unless(in_array($item->status->value, ['published', 'change_pending']) && $item->published_at && $item->retailer->status->value === 'approved', 404);
    }
}
