<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExchangeDifferencePayment extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'amount_cents' => ExactIntegerCast::class, 'simulated' => 'boolean'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ExchangeVersion::class, 'exchange_version_id');
    }
}
