<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use Database\Factories\InventoryImageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryImage extends Model
{
    /** @use HasFactory<InventoryImageFactory> */
    use HasFactory;

    protected $attributes = ['disk' => 'local', 'position' => 0];

    protected $fillable = ['inventory_item_id', 'disk', 'path', 'alt_text', 'position'];

    protected function casts(): array
    {
        return [
            'position' => ExactIntegerCast::class,
        ];
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
