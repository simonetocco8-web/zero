<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use Illuminate\Database\Eloquent\Model;

class MarketplacePayment extends Model
{
    protected $fillable = ['order_id', 'provider', 'reference', 'status', 'amount_cents', 'currency', 'simulated', 'checkout_url', 'stripe_payment_intent_id', 'stripe_charge_id', 'bonus_cents', 'card_cents', 'funding_snapshot'];

    protected function casts(): array
    {
        return ['bonus_cents' => ExactIntegerCast::class, 'card_cents' => ExactIntegerCast::class, 'funding_snapshot' => 'array', 'simulated' => 'boolean', 'amount_cents' => ExactIntegerCast::class];
    }
}
