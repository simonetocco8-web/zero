<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use App\Enums\AvailabilityRequestStatus;
use Brick\Math\BigDecimal;
use Database\Factories\AvailabilityRequestFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AvailabilityRequest extends Model
{
    /** @use HasFactory<AvailabilityRequestFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'new'];

    protected $fillable = ['inventory_item_id', 'retailer_id', 'customer_company', 'quantity_milliunits', 'quantity', 'privacy_accepted_at', 'privacy_policy_version', 'customer_name', 'customer_email', 'customer_phone', 'message', 'status', 'contacted_at', 'closed_at'];

    protected $hidden = ['customer_name', 'customer_company', 'customer_email', 'customer_phone', 'message', 'privacy_accepted_at', 'privacy_policy_version'];

    protected function casts(): array
    {
        return [
            'quantity_milliunits' => ExactIntegerCast::class, 'privacy_accepted_at' => 'immutable_datetime',
            'status' => AvailabilityRequestStatus::class,
            'contacted_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    protected function quantity(): Attribute
    {
        return Attribute::make(
            get: fn () => isset($this->attributes['quantity_milliunits']) ? (string) BigDecimal::of($this->attributes['quantity_milliunits'])->dividedBy(1000, 3) : null,
            set: function (mixed $value) {
                if (! is_int($value) && (! is_string($value) || ! preg_match('/^[0-9]+(?:\.[0-9]{1,3})?$/D', $value))) {
                    throw new \InvalidArgumentException('Quantity must be exact.');
                }

                return ['quantity_milliunits' => BigDecimal::of($value)->multipliedBy(1000)->toBigInteger()->toInt()];
            }
        );
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
