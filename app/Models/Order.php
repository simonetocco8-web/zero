<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected static function booted(): void
    {
        static::updating(function ($model) {
            foreach (array_keys($model->getDirty()) as $field) {
                if (! in_array($field, ['status', 'updated_at'], true)) {
                    throw new \LogicException('Order snapshots are immutable.');
                }
            }
        });
        static::deleting(fn () => throw new \LogicException('Historical orders cannot be deleted.'));
    }

    protected $fillable = ['checkout_key', 'user_id', 'status', 'currency', 'subtotal_cents', 'shipping_cents', 'tax_cents', 'taxable_cents', 'total_cents', 'totals_provisional', 'customer_snapshot'];

    protected function casts(): array
    {
        return ['status' => OrderStatus::class, 'customer_snapshot' => 'array', 'totals_provisional' => 'boolean', 'subtotal_cents' => ExactIntegerCast::class, 'shipping_cents' => ExactIntegerCast::class, 'tax_cents' => ExactIntegerCast::class, 'taxable_cents' => ExactIntegerCast::class, 'total_cents' => ExactIntegerCast::class];
    }

    public function vendorOrders(): HasMany
    {
        return $this->hasMany(VendorOrder::class);
    }

    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(MarketplacePayment::class);
    }
}
