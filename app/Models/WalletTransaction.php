<?php

namespace App\Models;

use App\Casts\CurrencyCode;
use App\Casts\ExactIntegerCast;
use App\Enums\WalletTransactionType;
use App\Models\Concerns\AppendOnly;
use Database\Factories\WalletTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletTransaction extends Model
{
    use AppendOnly;

    /** @use HasFactory<WalletTransactionFactory> */
    use HasFactory;

    protected $attributes = ['currency' => 'EUR'];

    protected $fillable = ['retailer_id', 'sale_item_id', 'payout_request_id', 'integration_event_id', 'idempotency_key', 'type', 'currency', 'amount_cents', 'description', 'available_at'];

    protected function casts(): array
    {
        return [
            'type' => WalletTransactionType::class,
            'currency' => CurrencyCode::class,
            'amount_cents' => ExactIntegerCast::class,
            'available_at' => 'immutable_datetime',
        ];
    }

    public function retailer(): BelongsTo
    {
        return $this->belongsTo(Retailer::class);
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }

    public function payoutRequest(): BelongsTo
    {
        return $this->belongsTo(PayoutRequest::class);
    }

    public function integrationEvent(): BelongsTo
    {
        return $this->belongsTo(IntegrationEvent::class);
    }
}
