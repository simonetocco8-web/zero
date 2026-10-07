<?php

namespace App\Models;

use App\Casts\CurrencyCode;
use App\Casts\ExactIntegerCast;
use App\Enums\InventoryCondition;
use App\Enums\InventoryStatus;
use App\Models\Concerns\HasExactQuantity;
use App\Support\MoneyMath;
use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    use HasExactQuantity;

    /** @use HasFactory<InventoryItemFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'draft', 'condition' => 'new', 'unit' => 'piece', 'currency' => 'EUR', 'pickup_available' => false, 'shipping_available' => false, 'exchange_available' => false];

    protected $fillable = ['retailer_id', 'name', 'brand', 'category', 'sku', 'ean', 'quantity', 'quantity_milliunits', 'unit', 'currency', 'list_price_cents', 'zero_price_cents', 'condition', 'province', 'pickup_available', 'shipping_available', 'exchange_available', 'description', 'status', 'reviewed_by', 'rejection_reason', 'published_at', 'external_provider', 'external_product_id'];

    protected function casts(): array
    {
        return [
            'proposed_data' => 'array', 'proposed_images' => 'array',
            'quantity_milliunits' => ExactIntegerCast::class,
            'currency' => CurrencyCode::class,
            'list_price_cents' => ExactIntegerCast::class,
            'zero_price_cents' => ExactIntegerCast::class,
            'condition' => InventoryCondition::class,
            'status' => InventoryStatus::class,
            'pickup_available' => 'boolean',
            'shipping_available' => 'boolean',
            'exchange_available' => 'boolean',
            'submitted_at' => 'immutable_datetime', 'published_at' => 'immutable_datetime',
        ];
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function images(): HasMany
    {
        return $this->hasMany(InventoryImage::class);
    }

    public function availabilityRequests(): HasMany
    {
        return $this->hasMany(AvailabilityRequest::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function publications(): HasMany
    {
        return $this->hasMany(StorePublication::class);
    }

    public function inventoryValueCents(): int
    {
        return MoneyMath::lineTotalCents($this->zero_price_cents, $this->quantity_milliunits);
    }
}
