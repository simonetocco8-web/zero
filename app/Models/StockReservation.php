<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use App\Enums\StockReservationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class StockReservation extends Model
{
    protected $fillable = ['uuid', 'inventory_item_id', 'user_id', 'order_id', 'exchange_id', 'exchange_version_id', 'quantity_milliunits', 'status', 'expires_at', 'consumed_at', 'released_at', 'idempotency_key'];

    protected function casts(): array
    {
        return ['status' => StockReservationStatus::class, 'quantity_milliunits' => ExactIntegerCast::class, 'expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime', 'released_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function ($reservation) {
            $reservation->uuid ??= (string) Str::uuid();
        });
        static::updating(function ($reservation) {
            if ($reservation->isDirty(['inventory_item_id', 'order_id', 'user_id', 'exchange_id', 'exchange_version_id', 'quantity_milliunits', 'expires_at', 'idempotency_key', 'uuid'])) {
                throw new \LogicException('Reservation identity, quantity and original expiry are immutable.');
            }
            if ($reservation->getRawOriginal('status') !== 'active') {
                throw new \LogicException('A terminal reservation cannot be reopened.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Reservation history must be retained.'));
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
