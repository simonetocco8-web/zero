<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VendorOrder extends Model
{
    protected static function booted(): void
    {
        static::updating(function ($model) {
            foreach (array_keys($model->getDirty()) as $field) {
                if (! in_array($field, ['status', 'updated_at', 'carrier', 'tracking_reference', 'shipped_at', 'ready_for_pickup_at', 'completed_at'], true)) {
                    throw new \LogicException('Order snapshots are immutable.');
                }
            }
        });
        static::deleting(fn () => throw new \LogicException('Historical orders cannot be deleted.'));
    }

    protected $fillable = ['funds_flow_snapshot', 'carrier', 'tracking_reference', 'shipped_at', 'ready_for_pickup_at', 'completed_at', 'order_id', 'retailer_id', 'status', 'currency', 'subtotal_cents', 'shipping_cents', 'tax_cents', 'taxable_cents', 'total_cents', 'totals_provisional', 'delivery_mode', 'retailer_snapshot', 'address_snapshot', 'tax_snapshot'];

    protected function casts(): array
    {
        return ['funds_flow_snapshot' => 'array', 'shipped_at' => 'immutable_datetime', 'ready_for_pickup_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'status' => OrderStatus::class, 'retailer_snapshot' => 'array', 'address_snapshot' => 'array', 'tax_snapshot' => 'array', 'totals_provisional' => 'boolean', 'subtotal_cents' => ExactIntegerCast::class, 'shipping_cents' => ExactIntegerCast::class, 'tax_cents' => ExactIntegerCast::class, 'taxable_cents' => ExactIntegerCast::class, 'total_cents' => ExactIntegerCast::class];
    }

    public function events(): HasMany
    {
        return $this->hasMany(VendorOrderEvent::class)->orderBy('id');
    }

    public function returnCases(): HasMany
    {
        return $this->hasMany(MarketplaceReturnCase::class)->latest('id');
    }

    public function refund(): HasOne
    {
        return $this->hasOne(MarketplaceRefund::class);
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }
}
