<?php

namespace App\Models;

use App\Enums\ExchangeStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exchange extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['status' => ExchangeStatus::class, 'expires_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function ($model) {
            if ($model->isDirty(['uuid', 'request_key', 'request_hash', 'retailer_a_id', 'retailer_b_id', 'requested_item_id'])) {
                throw new \LogicException('Exchange participants and source are immutable.');
            }
        });
        static::deleting(fn () => throw new \LogicException('Exchange history must be retained.'));
    }

    public function retailerA(): BelongsTo
    {
        return $this->belongsTo(Retailer::class, 'retailer_a_id');
    }

    public function retailerB(): BelongsTo
    {
        return $this->belongsTo(Retailer::class, 'retailer_b_id');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(ExchangeVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ExchangeVersion::class)->orderBy('number');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ExchangeEvent::class)->orderBy('id');
    }

    public function stockReservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }
}
