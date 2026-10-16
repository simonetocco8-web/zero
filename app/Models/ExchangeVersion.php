<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use App\Models\Concerns\ImmutableExchangeRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ExchangeVersion extends Model
{
    use ImmutableExchangeRecord;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value_a_cents' => ExactIntegerCast::class, 'value_b_cents' => ExactIntegerCast::class, 'difference_cents' => ExactIntegerCast::class, 'delivery_a' => 'array', 'delivery_b' => 'array', 'tax_snapshot' => 'array', 'commission_snapshot' => 'array', 'parties_snapshot' => 'array', 'payment_provisional' => 'boolean', 'expires_at' => 'immutable_datetime'];
    }

    public function exchange(): BelongsTo
    {
        return $this->belongsTo(Exchange::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExchangeLine::class)->orderBy('side')->orderBy('inventory_item_id');
    }

    public function acceptances(): HasMany
    {
        return $this->hasMany(ExchangeAcceptance::class);
    }

    public function completions(): HasMany
    {
        return $this->hasMany(ExchangeCompletion::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(ExchangeDifferencePayment::class);
    }
}
