<?php

namespace App\Services;

use App\Models\InventoryItem;
use Illuminate\Database\Eloquent\Builder;

class PublicInventory
{
    public function query(): Builder
    {
        return InventoryItem::whereIn('status', ['published', 'change_pending'])->whereNotNull('published_at')->whereHas('retailer', fn ($q) => $q->where('status', 'approved'));
    }

    public function isAvailable(InventoryItem $item): bool
    {
        return ! $item->trashed() && in_array($item->status->value, ['published', 'change_pending'], true) && $item->published_at !== null && $item->retailer->status->value === 'approved';
    }

    public function ensureAvailable(InventoryItem $item): void
    {
        abort_unless($this->isAvailable($item), 404);
    }
}
