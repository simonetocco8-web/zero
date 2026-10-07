<?php

namespace App\Models;

use App\Enums\AvailabilityRequestStatus;
use Database\Factories\AvailabilityRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvailabilityRequest extends Model
{
    /** @use HasFactory<AvailabilityRequestFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'new'];

    protected $fillable = ['inventory_item_id', 'retailer_id', 'customer_name', 'customer_email', 'customer_phone', 'message', 'status', 'contacted_at', 'closed_at'];

    protected $hidden = ['customer_email', 'customer_phone', 'message'];

    protected function casts(): array
    {
        return [
            'status' => AvailabilityRequestStatus::class,
            'contacted_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }
}
