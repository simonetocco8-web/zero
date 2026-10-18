<?php

namespace App\Actions;

use App\Models\InventoryItem;
use App\Models\Retailer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteInventoryItem
{
    public function handle(User $actor, InventoryItem $item): void
    {
        DB::transaction(function () use ($actor, $item) {
            $retailer = Retailer::lockForUpdate()->findOrFail($item->retailer_id);
            if ($actor->role->value !== 'admin') {
                Gate::forUser($actor)->authorize('operate', $retailer);
            }
            $current = InventoryItem::lockForUpdate()->findOrFail($item->id);
            Gate::forUser($actor)->authorize('delete', $current);
            if ($current->stockReservations()->where('status', 'active')->where('expires_at', '>', now())->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['item' => 'Articolo prenotato: attendi la chiusura delle prenotazioni prima di eliminarlo.']);
            }
            $current->status = 'archived';
            $current->deleted_by = $actor->id;
            $current->save();
            $current->delete();
        }, 5);
    }
}
