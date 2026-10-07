<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillingCheckout extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'retailer_id', 'billing_interval', 'price_id', 'price_cents', 'status', 'checkout_session_id', 'checkout_url', 'expires_at', 'completed_at', 'error_code'];

    protected $hidden = ['checkout_session_id', 'checkout_url'];

    protected function casts(): array
    {
        return ['price_cents' => ExactIntegerCast::class, 'checkout_url' => 'encrypted', 'expires_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }
}
