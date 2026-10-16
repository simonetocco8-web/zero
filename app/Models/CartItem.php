<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $fillable = ['inventory_item_id', 'quantity_milliunits', 'delivery_mode'];

    protected function casts(): array
    {
        return ['quantity_milliunits' => ExactIntegerCast::class, 'quantity_milliunits' => ExactIntegerCast::class];
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
