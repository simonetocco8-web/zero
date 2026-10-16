<?php

namespace App\Models;

use App\Casts\ExactIntegerCast;
use App\Models\Concerns\ImmutableExchangeRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketplaceRefund extends Model
{
    use ImmutableExchangeRecord;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount_cents' => ExactIntegerCast::class, 'simulated' => 'boolean'];
    }

    public function vendorOrder(): BelongsTo
    {
        return $this->belongsTo(VendorOrder::class);
    }
}
