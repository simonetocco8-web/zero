<?php

namespace App\Models;

use App\Casts\CurrencyCode;
use App\Casts\ExactIntegerCast;
use App\Enums\SaleStatus;
use Database\Factories\SaleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    /** @use HasFactory<SaleFactory> */
    use HasFactory;

    protected $attributes = ['status' => 'pending', 'currency' => 'EUR'];

    protected $fillable = ['provider', 'external_order_id', 'status', 'currency', 'total_cents', 'customer_email', 'ordered_at', 'paid_at'];

    protected $hidden = ['customer_email'];

    protected function casts(): array
    {
        return [
            'status' => SaleStatus::class,
            'currency' => CurrencyCode::class,
            'total_cents' => ExactIntegerCast::class,
            'ordered_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }
}
