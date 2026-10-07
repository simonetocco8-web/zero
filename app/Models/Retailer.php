<?php

namespace App\Models;

use App\Enums\RetailerStatus;
use App\Enums\SubscriptionStatus;
use Database\Factories\RetailerFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Retailer extends Model
{
    /** @use HasFactory<RetailerFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'pending'];

    protected $fillable = ['user_id', 'company_name', 'vat_number', 'contact_name', 'contact_email', 'phone', 'address', 'city', 'province', 'region', 'website', 'iban', 'status', 'reviewed_by', 'rejection_reason', 'approved_at', 'rejected_at'];

    protected $hidden = ['iban'];

    protected function casts(): array
    {
        return [
            'status' => RetailerStatus::class,
            'iban' => 'encrypted',
            'approved_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function availabilityRequests(): HasMany
    {
        return $this->hasMany(AvailabilityRequest::class);
    }

    public function saleItems(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function payoutRequests(): HasMany
    {
        return $this->hasMany(PayoutRequest::class);
    }

    public function latestSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->latestOfMany();
    }

    public function latestBillingSubscription(): HasOne
    {
        return $this->hasOne(BillingSubscription::class)->latestOfMany();
    }

    public function billingSubscriptions(): HasMany
    {
        return $this->hasMany(BillingSubscription::class);
    }

    public function activeSubscription(): HasOne
    {
        return $this->hasOne(Subscription::class)->where('status', SubscriptionStatus::Active->value);
    }
}
