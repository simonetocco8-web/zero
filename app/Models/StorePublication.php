<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorePublication extends Model
{
    protected $fillable = ['inventory_item_id', 'requested_by', 'operation', 'status', 'payload', 'result', 'error_code', 'processing_at', 'completed_at'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'result' => 'array', 'processing_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
