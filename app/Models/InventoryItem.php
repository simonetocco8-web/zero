<?php

namespace App\Models;

use App\Casts\CurrencyCode;
use App\Casts\ExactIntegerCast;
use App\Enums\InventoryCondition;
use App\Enums\InventoryStatus;
use App\Models\Concerns\HasExactQuantity;
use App\Services\StockReservations;
use App\Support\MoneyMath;
use Brick\Math\BigDecimal;
use Database\Factories\InventoryItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class InventoryItem extends Model
{
    use HasExactQuantity;

    /** @use HasFactory<InventoryItemFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'draft', 'condition' => 'new', 'unit' => 'piece', 'currency' => 'EUR', 'pickup_available' => false, 'shipping_available' => false, 'exchange_available' => false];

    protected $fillable = ['category_id', 'shipping_cost_cents', 'delivery_notes', 'vat_treatment', 'retailer_id', 'name', 'brand', 'category', 'sku', 'ean', 'quantity', 'quantity_milliunits', 'unit', 'currency', 'list_price_cents', 'zero_price_cents', 'condition', 'province', 'pickup_available', 'shipping_available', 'exchange_available', 'description', 'status', 'reviewed_by', 'rejection_reason', 'published_at', 'external_provider', 'external_product_id'];

    protected function casts(): array
    {
        return [
            'proposed_data' => 'array', 'proposed_images' => 'array',
            'stock_version' => 'integer', 'proposed_stock_version' => 'integer',
            'category_id' => ExactIntegerCast::class,
            'shipping_cost_cents' => ExactIntegerCast::class,
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

    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function getAvailableQuantityMilliunitsAttribute(): int
    {
        if (array_key_exists('reserved_milliunits', $this->attributes)) {
            return max(0, $this->quantity_milliunits - (int) $this->attributes['reserved_milliunits']);
        }

        return app(StockReservations::class)->available($this);
    }

    public function getAvailableQuantityAttribute(): string
    {
        return (string) BigDecimal::of($this->available_quantity_milliunits)->dividedBy(1000, 3);
    }

    public function categoryRecord(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function vatLabel(): string
    {
        return config('marketplace.vat_treatments.'.$this->vat_treatment) ?? 'Trattamento IVA da definire';
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

    public function latestPublication(): HasOne
    {
        return $this->hasOne(StorePublication::class)->latestOfMany();
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
