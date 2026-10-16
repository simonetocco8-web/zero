<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    protected static function booted(): void
    {
        static::updating(function ($model) {
            foreach (array_keys($model->getDirty()) as $field) {
                if (! in_array($field, ['updated_at'], true)) {
                    throw new \LogicException('Order snapshots are immutable.');
                }
            }
        });
        static::deleting(fn () => throw new \LogicException('Historical orders cannot be deleted.'));
    }

    protected $fillable = ['inventory_item_id', 'name', 'sku', 'unit', 'unit_price_cents', 'quantity_milliunits', 'subtotal_cents', 'shipping_cost_cents', 'tax_cents', 'taxable_cents', 'total_cents', 'commission_basis_points', 'commission_cents', 'tax_snapshot', 'product_snapshot'];

    protected function casts(): array
    {
        return ['tax_snapshot' => 'array', 'product_snapshot' => 'array', 'unit_price_cents' => ExactIntegerCast::class, 'quantity_milliunits' => ExactIntegerCast::class, 'subtotal_cents' => ExactIntegerCast::class, 'shipping_cost_cents' => ExactIntegerCast::class, 'tax_cents' => ExactIntegerCast::class, 'taxable_cents' => ExactIntegerCast::class, 'total_cents' => ExactIntegerCast::class, 'commission_basis_points' => ExactIntegerCast::class, 'commission_cents' => ExactIntegerCast::class];
    }

    public function vendorOrder(): BelongsTo
    {
        return $this->belongsTo(VendorOrder::class);
    }
}
