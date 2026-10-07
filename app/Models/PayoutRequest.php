<?php

namespace App\Models;

use App\Casts\CurrencyCode;
use App\Casts\ExactIntegerCast;
use App\Enums\PayoutStatus;
use Database\Factories\PayoutRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayoutRequest extends Model
{
    /** @use HasFactory<PayoutRequestFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'pending', 'currency' => 'EUR'];

    protected $fillable = ['retailer_id', 'currency', 'amount_cents', 'status', 'iban', 'reviewed_by', 'rejection_reason', 'payment_reference', 'paid_at', 'rejected_at'];

    protected $hidden = ['iban'];

    protected function casts(): array
    {
        return [
            'currency' => CurrencyCode::class,
            'amount_cents' => ExactIntegerCast::class,
            'status' => PayoutStatus::class,
            'iban' => 'encrypted',
            'paid_at' => 'immutable_datetime',
            'rejected_at' => 'immutable_datetime',
        ];
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
