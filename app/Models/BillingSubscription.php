<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BillingSubscription extends Model
{
    protected $fillable = ['retailer_id', 'billing_checkout_id', 'provider', 'external_subscription_id', 'external_customer_id', 'status', 'invoice_paid', 'price_id', 'billing_interval', 'price_cents', 'current_period_end', 'cancel_at_period_end', 'last_event_created'];

    protected $hidden = ['external_customer_id', 'external_subscription_id'];

    protected function casts(): array
    {
        return ['price_cents' => ExactIntegerCast::class, 'last_event_created' => ExactIntegerCast::class, 'current_period_end' => 'immutable_datetime', 'invoice_paid' => 'boolean', 'cancel_at_period_end' => 'boolean'];
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(BillingCheckout::class, 'billing_checkout_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
